<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\VacantPosition;

class VacantController extends Controller
{
    public function getVacantList()
    {
        $result = VacantPosition::with(['business' => function ($query) {
            $query->select('user_id', 'bs_name', 'bs_locality', 'bs_country', 'bs_state');
        }])
            ->where('status', true)
            ->orderBy('created_at', 'desc')
            ->select('id', 'vacant_name', 'category', 'activities', 'created_at', 'status', 'user_id')
            ->get();

        return response()->json([
            'vacancies' => $result
        ]);
    }

    public function showVacant($id)
    {
        $vacant = VacantPosition::with('business')->with('image')->find($id); // Busca directamente en la base de datos

        if (!$vacant) {
            return response()->json([
                'res' => false,
                'message' => 'Vacant not found',
            ], 404);
        }

        return response()->json([
            'res' => true,
            'vacant' => $vacant,
        ], 200);
    }
}
