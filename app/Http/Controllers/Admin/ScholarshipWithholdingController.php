<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ScholarshipWithholding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScholarshipWithholdingController extends Controller
{
    /**
     * Lists a becario's retention ledger. Defaults to pending rows with a
     * balance > 0 (what "Pago meses retenidos" needs to offer), ordered
     * oldest period first — the order an operator expects to liquidate them.
     */
    public function index(Request $request, int $userId): JsonResponse
    {
        $status = $request->validate([
            'status' => 'nullable|in:pending,all',
        ])['status'] ?? 'pending';

        $query = ScholarshipWithholding::with([
            'payments' => fn ($q) => $q->where('is_voided', false)
                ->with('createdBy:id,first_name,last_name')
                ->orderByDesc('created_at'),
        ])->where('user_id', $userId);

        if ($status === 'pending') {
            $query->pending();
        }

        $withholdings = $query
            ->orderBy('period_year')
            ->orderBy('period_month')
            ->get();

        return response()->json(['res' => true, 'data' => $withholdings]);
    }
}
