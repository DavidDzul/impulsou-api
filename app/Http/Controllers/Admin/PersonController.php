<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Scholarship\GraduateBecarioAction;
use App\Http\Controllers\Controller;
use App\Models\ScholarshipRefrend;
use App\Models\ScholarshipRefrendLog;
use App\Enums\RefrendStatus;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use App\Mail\WelcomeMail;

class PersonController extends Controller
{
    public function __construct(private GraduateBecarioAction $graduateBecario)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $rules = array_merge(User::createRulesUser(), [
            'user_type' => 'required|string|in:BEC_ACTIVE,BEC_INACTIVE',
        ]);

        $data = $request->validate($rules);

        $existingUser = User::where('enrollment', $data['enrollment'])->first();
        if ($existingUser) {
            return response()->json([
                'res' => false,
                'msg' => 'La matrícula ya está registrada.',
            ], 409);
        }

        $data['active'] = true;
        $data['password'] = Hash::make($data['password']);

        $person = User::create($data);

        Mail::to($person->email)->send(new WelcomeMail($person, true));

        return response()->json([
            'res' => true,
            'msg' => 'Persona creada con éxito',
            'createPerson' => $person,
        ], 201);
    }

    /**
     * Marca al becario como egresado:
     * - Cambia user_type a BEC_INACTIVE.
     * - Cancela el refrendo activo del mes actual si existe.
     * - Registra la acción en logs.
     */
    public function graduate(Request $request, int $userId): JsonResponse
    {
        $data = $request->validate([
            'comment' => 'required|string|max:1000',
        ]);

        $person = User::findOrFail($userId);

        if ($person->user_type !== 'BEC_ACTIVE') {
            return response()->json([
                'res' => false,
                'msg' => 'El usuario no es un becario activo.',
            ], 422);
        }

        DB::transaction(function () use ($person, $data) {
            // Cancelar refrendo activo del mes actual si existe. Esto se
            // queda en el controlador (sdd/egresado-status-timing, design
            // D3) — no forma parte de GraduateBecarioAction, que solo
            // encapsula el flip de user_type + el log 'graduated' comunes a
            // ambos flujos (manual y automático).
            $activeRefrend = ScholarshipRefrend::where('user_id', $person->id)
                ->whereIn('status', [
                    RefrendStatus::DRAFT->value,
                    RefrendStatus::ATENCION_REVIEW->value,
                    RefrendStatus::PEDAGOGIA_REVIEW->value,
                ])
                ->where('period_year', now()->year)
                ->where('period_month', now()->month)
                ->first();

            if ($activeRefrend) {
                $activeRefrend->update(['status' => RefrendStatus::CANCELLED->value]);

                ScholarshipRefrendLog::create([
                    'scholarship_refrend_id' => $activeRefrend->id,
                    'performed_by_id'        => auth()->id(),
                    'action'                 => 'cancelled_by_graduation',
                    'notes'                  => $data['comment'],
                ]);
            }

            $this->graduateBecario->execute($person, $activeRefrend, $data['comment'], auth()->id());
        });

        return response()->json([
            'res'  => true,
            'msg'  => 'Becario marcado como egresado exitosamente.',
            'data' => $person->fresh(),
        ]);
    }
}
