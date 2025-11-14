<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\Generation;

class GenerationController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        // $user = auth()->user();
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'res' => false,
                'msg' => 'Usuario no autenticado.'
            ], 401);
        }

        // Si el usuario pertenece al campus "MERIDA", obtiene todos los registros
        if ($user->isRoot()) {
            $data = Generation::all();
        } else {
            // Si no, solo obtiene los registros de su campus
            $data = Generation::where('campus', $user->campus)->get();
        }

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

        return response()->json([
            'generation' => $generation
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        // $terrain = Terrain::findOrFail($id);
        // $data = $request->validate(Terrain::$updateRules);

        // $terrain->update($data);

        return response()->json([
            'message' => 'Terreno actualizado correctamente',
            // 'terrain' => $terrain
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id) {}
}
