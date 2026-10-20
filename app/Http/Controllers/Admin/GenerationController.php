<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Exceptions\GenerationHasPendingBecariosException;
use App\Models\Generation;
use App\Support\Scholarship\GenerationPendingBecariosCounter;

class GenerationController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    // public function index(Request $request): JsonResponse
    // {
    //     // $user = auth()->user();
    //     $user = $request->user();

    //     if (!$user) {
    //         return response()->json([
    //             'res' => false,
    //             'msg' => 'Usuario no autenticado.'
    //         ], 401);
    //     }

    //     // Si el usuario pertenece al campus "MERIDA", obtiene todos los registros
    //     if ($user->isRoot()) {
    //         $data = Generation::all();
    //     } else {
    //         // Si no, solo obtiene los registros de su campus
    //         $data = Generation::where('campus', $user->campus)->get();
    //     }

    //     return response()->json([
    //         'res' => true,
    //         'generations' => $data
    //     ]);
    // }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'res' => false,
                'msg' => 'Usuario no autenticado.'
            ], 401);
        }

        // Usamos el query builder para evitar repetir código
        $query = Generation::query();

        // Filtramos por campus si no es Root
        if (!$user->isRoot()) {
            $query->where('campus', $user->campus);
        }

        // Ordenamos alfabéticamente por el nombre y ejecutamos la consulta
        $data = $query->orderBy('generation_name', 'asc')->get();

        // sdd/generaciones-activas-refrendos R2: one grouped query for all rows.
        $counts = GenerationPendingBecariosCounter::countByGeneration($data->pluck('id')->all());
        $data->each(fn (Generation $g) => $g->setAttribute('pending_becarios_count', $counts[$g->id] ?? 0));

        return response()->json([
            'res' => true,
            'generations' => $data
        ]);
    }


    /**
     * Store a newly created resource in storage.
     */

    public function store(Request $request)
    {
        $data = $request->validate(Generation::$createRules);
        $data['generation_active'] = $data['generation_active'] ?? false;

        // Validar existencia previa
        $exists = Generation::where('generation_name', $data['generation_name'])
            ->where('campus', $data['campus'])
            ->exists();

        if ($exists) {
            return response()->json([
                'res' => false,
                'msg' => 'Ya existe una generación con ese nombre y campus.',
            ], 422);
        }

        $generation = Generation::create($data);

        return response()->json([
            'res' => true,
            'msg' => 'Generación creada con éxito',
            'createGeneration' => $generation,
        ], 201);
    }


    /**
     * Display the specified resource.
     */
    public function show(string $id): JsonResponse
    {
        $generation = Generation::findOrFail($id);
        $generation->setAttribute('pending_becarios_count', GenerationPendingBecariosCounter::countFor($generation->id));

        return response()->json([
            'generation' => $generation
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $gen = Generation::findOrFail($id);
        $data = $request->validate(Generation::updateRules());

        // sdd/generaciones-activas-refrendos R3/R4: only active -> inactive is guarded.
        $isDeactivating = $gen->generation_active
            && array_key_exists('generation_active', $data)
            && !filter_var($data['generation_active'], FILTER_VALIDATE_BOOLEAN);

        if ($isDeactivating) {
            $pending = GenerationPendingBecariosCounter::countFor($gen->id);
            if ($pending > 0) {
                return (new GenerationHasPendingBecariosException($pending))->toResponse();
            }
        }

        $gen->update($data);
        $gen->setAttribute('pending_becarios_count', GenerationPendingBecariosCounter::countFor($gen->id));

        return response()->json([
            'message' => 'Actualizado correctamente',
            'data' => $gen
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id) {}
}
