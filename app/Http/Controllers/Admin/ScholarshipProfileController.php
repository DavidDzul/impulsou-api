<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ScholarshipType;
use App\Exceptions\ReticulaAdvanceConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreScholarshipProfileRequest;
use App\Http\Requests\UpdateScholarshipProfileConfigRequest;
use App\Http\Requests\UpdateScholarshipProfileRequest;
use App\Models\ScholarshipProfile;
use App\Models\User;
use App\Services\Scholarship\ReticulaAdvanceGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ScholarshipProfileController extends Controller
{
    public function show(int $userId)
    {
        $profile = ScholarshipProfile::with(['user', 'grantedBy:id,first_name,last_name'])
            ->where('user_id', $userId)
            ->first();

        if (!$profile) {
            return response()->json(['res' => false, 'data' => null, 'msg' => 'Perfil no encontrado.'], 404);
        }

        return response()->json(['res' => true, 'data' => $profile]);
    }

    public function store(StoreScholarshipProfileRequest $request)
    {
        $data = $request->safe()->toArray();

        if (array_key_exists('temporary_increase_amount', $data)) {
            $data['temporary_increase_granted_by_id'] = $data['temporary_increase_amount'] !== null
                ? $request->user()->id
                : null;
        }

        $profile = ScholarshipProfile::create($data);

        return response()->json(['res' => true, 'data' => $profile->fresh('user')], 201);
    }

    public function update(UpdateScholarshipProfileRequest $request, int $userId)
    {
        $profile = ScholarshipProfile::where('user_id', $userId)->firstOrFail();

        $data = $request->safe()->except('replace_temporary_increase');

        // The temporary increase is an atomic block of 4 columns + who
        // granted it. The controller only writes keys present in the
        // request (mass "safe" data), so a client sending ONLY
        // `temporary_increase_amount: null` (without the other 3 fields)
        // must still clear the ENTIRE block — otherwise valid_from/
        // valid_until/reason are left "orphaned" in the DB with a stale
        // (possibly future) date, which later false-positives the "already
        // has a valid increase" check on a legitimate new grant.
        if ($request->has('temporary_increase_amount')) {
            if ($request->input('temporary_increase_amount') === null) {
                $data['temporary_increase_amount']         = null;
                $data['temporary_increase_valid_from']     = null;
                $data['temporary_increase_valid_until']    = null;
                $data['temporary_increase_reason']         = null;
                $data['temporary_increase_granted_by_id']  = null;
            } else {
                $data['temporary_increase_granted_by_id'] = $request->user()->id;
            }
        }

        $profile->update($data);

        return response()->json(['res' => true, 'data' => $profile->fresh(['user', 'grantedBy:id,first_name,last_name'])]);
    }

    /**
     * Actualiza los 4 campos de configuración de beca (scholarship_type,
     * monthly_amount, monto_apoyo, is_cert_unid). Gateado por
     * el middleware `permission:ADM_EDIT_SCHOLARSHIP_PROFILE` en la ruta
     * (design D2/D9, sdd/scholarship-profile-config-to-admin). Distinto de
     * update() (que ahora prohíbe estos 4 campos, design D3).
     *
     * sdd/perfil-beca-montos-admin: es un upsert por user_id (un solo perfil
     * por usuario). Si el becario no tiene perfil lo crea; siempre responde
     * 200 y marca config_set_at = now(). 404 si el usuario no existe.
     */
    public function updateConfig(UpdateScholarshipProfileConfigRequest $request, int $userId)
    {
        if (!User::whereKey($userId)->exists()) {
            return response()->json(['res' => false, 'msg' => 'Becario no encontrado.'], 404);
        }

        // One profile per user (unique user_id): always keyed on user_id only.
        $profile = ScholarshipProfile::firstOrNew(['user_id' => $userId]);

        $data = $request->safe()->toArray();

        // iu_payment_amount (sdd/scholarship-telmex-iu-split): `exclude_unless`
        // (request rules) only drops the field from the VALIDATED payload
        // when the type isn't TELMEX_IU — it does not touch the DB. Without
        // this explicit clear, switching a profile away from TELMEX_IU would
        // leave a stale iu_payment_amount behind (mass-update only writes
        // present keys). Mirrors the explicit-null-on-type-change pattern
        // already used for temporary_increase_* in update() above.
        if ($data['scholarship_type'] !== ScholarshipType::TELMEX_IU->value) {
            $data['iu_payment_amount'] = null;
        }

        // sdd/perfil-beca-montos-admin: the marker is written ONLY here, on
        // every save (create or update) — generation skips profiles without it.
        $profile->fill($data);
        $profile->config_set_at = now();
        $profile->save();

        return response()->json(['res' => true, 'data' => $profile->fresh()]);
    }

    /**
     * Guarda o actualiza la retícula del becario (fechas + archivo).
     * Acepta multipart/form-data.
     */
    public function updateReticula(Request $request, int $userId, ReticulaAdvanceGuard $guard)
    {
        $profile = ScholarshipProfile::where('user_id', $userId)->firstOrFail();

        // The document is mandatory only until one is stored; afterwards a new
        // upload is optional and replaces the previous file.
        $fileRule = $profile->reticula_file_path ? 'nullable' : 'required';

        $data = $request->validate([
            'reticula_start_date' => 'required|date',
            'reticula_end_date'   => 'required|date|after:reticula_start_date',
            'file'                => "{$fileRule}|file|mimes:pdf,jpg,jpeg,png|max:20480",
        ], [
            'file.required' => 'El documento es obligatorio.',
        ]);

        // Lock order vs RecordAdvancePaymentAction (origin refrend -> profile):
        // this endpoint only ever takes the profile lock and reads advance
        // months WITHOUT locking, so it never waits on an origin row and
        // cannot close a lock cycle. If a record races us it blocks on the
        // profile until we commit and then validates against the new dates.
        $oldPath = null;

        try {
            DB::transaction(function () use ($request, $userId, $data, $guard, &$oldPath) {
                $profile = ScholarshipProfile::where('user_id', $userId)->lockForUpdate()->firstOrFail();

                // Guard BEFORE any file is stored.
                $guard->assertCompatible($profile, $data['reticula_end_date']);

                $attrs = [
                    'reticula_start_date' => $data['reticula_start_date'],
                    'reticula_end_date'   => $data['reticula_end_date'],
                ];

                if ($request->hasFile('file')) {
                    $oldPath = $profile->reticula_file_path;

                    $file = $request->file('file');
                    $path = $file->store("scholarships/{$userId}/reticula", 'public');

                    $attrs['reticula_file_path']     = $path;
                    $attrs['reticula_original_name'] = $file->getClientOriginalName();
                }

                $profile->update($attrs);
            });
        } catch (ReticulaAdvanceConflictException $e) {
            return response()->json([
                'res'  => false,
                'msg'  => $e->getMessage(),
                'code' => $e->code(),
                'data' => $e->payload(),
            ], $e->httpStatus());
        }

        // The previous document is removed only after the new state committed.
        if ($oldPath) {
            Storage::disk('public')->delete($oldPath);
        }

        $profile = ScholarshipProfile::where('user_id', $userId)->firstOrFail();

        return response()->json(['res' => true, 'data' => $profile->fresh()]);
    }
}
