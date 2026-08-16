<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Scholarship\VoidWithholdingPaymentAction;
use App\Http\Controllers\Controller;
use App\Models\ScholarshipWithholding;
use App\Models\ScholarshipWithholdingPayment;
use App\Services\Scholarship\PayableWithholdingWindow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScholarshipWithholdingController extends Controller
{
    /**
     * Lists a becario's retention ledger. Defaults to pending rows with a
     * balance > 0 (what "Pago meses retenidos" needs to offer), ordered
     * oldest period first — the order an operator expects to liquidate them.
     *
     * Optional `relative_year`/`relative_month` (both-or-neither) narrow
     * `data` down to the payable subset (window + top-2, per
     * PayableWithholdingWindow) and add an additive `meta` block. Without
     * them the response is byte-identical to the legacy shape.
     */
    public function index(Request $request, int $userId): JsonResponse
    {
        $validated = $request->validate([
            'status'         => 'nullable|in:pending,all',
            'relative_year'  => 'nullable|integer|min:2000|max:2100|required_with:relative_month',
            'relative_month' => 'nullable|integer|min:1|max:12|required_with:relative_year',
        ]);

        $status = $validated['status'] ?? 'pending';

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

        $relativeYear  = isset($validated['relative_year']) ? (int) $validated['relative_year'] : null;
        $relativeMonth = isset($validated['relative_month']) ? (int) $validated['relative_month'] : null;

        if ($relativeYear === null || $relativeMonth === null) {
            return response()->json(['res' => true, 'data' => $withholdings]);
        }

        // Eligibility is only meaningful over PENDING rows with an open balance
        // (the same set the `pending()` scope defines), regardless of the
        // `status` param used to build $withholdings above.
        $pendingBalance = $withholdings->filter(
            fn ($w) => $w->status === 'PENDING' && (float) $w->paid_amount < (float) $w->withheld_amount
        )->values();

        $payable = PayableWithholdingWindow::selectPayable($pendingBalance, $relativeYear, $relativeMonth);

        $totalPendingAmount = $pendingBalance->sum(
            fn ($w) => max(0, (float) $w->withheld_amount - (float) $w->paid_amount)
        );

        return response()->json([
            'res'  => true,
            'data' => $payable->values(),
            'meta' => [
                'relative_year'        => $relativeYear,
                'relative_month'       => $relativeMonth,
                'eligible_count'       => $payable->count(),
                'total_pending_count'  => $pendingBalance->count(),
                'total_pending_amount' => number_format($totalPendingAmount, 2, '.', ''),
            ],
        ]);
    }

    /**
     * Reverts a single withholding-payment child row. Requires the payment
     * to actually belong to the withholding named in the URL — the two
     * resource identifiers are independent path params, so a mismatched
     * combination must not silently operate on the wrong ledger row.
     */
    public function voidPayment(
        Request $request,
        ScholarshipWithholding $withholding,
        ScholarshipWithholdingPayment $payment
    ): JsonResponse {
        if ((int) $payment->withholding_id !== (int) $withholding->id) {
            return response()->json(['res' => false, 'msg' => 'Abono no encontrado.'], 404);
        }

        $data = $request->validate([
            'void_reason' => 'required|string|min:10|max:500',
        ]);

        try {
            $voided = app(VoidWithholdingPaymentAction::class)->execute($payment, $data['void_reason'], auth()->id());
        } catch (\DomainException $e) {
            return response()->json(['res' => false, 'msg' => $e->getMessage()], 422);
        }

        return response()->json(['res' => true, 'data' => $voided]);
    }
}
