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
    public function index(): JsonResponse
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json([
                'res' => false,
                'msg' => 'Usuario no autenticado.'
            ], 401);
        }

        // Si el usuario pertenece al campus "MERIDA", obtiene todos los registros
        if ($user->campus === 'MERIDA') {
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

    public function store(Request $request): JsonResponse
    {
        // $data = $request->validate(Terrain::$createRules);

        // $terrain = Terrain::create([
        //     'owner_id' => $data['owner_id'],
        //     'name' => $data['name'],
        //     'description' => $data['description'],
        //     'geometry' => $data['geometry'],
        //     'color' => $data['color'] ?? null,
        //     'dimensions' => $data['dimensions'] ?? null,
        //     'active' => true,
        //     'verified' => false
        // ]);

        return response()->json([
            'message' => 'Terreno creado correctamente',
            // 'terrain' => $terrain
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
