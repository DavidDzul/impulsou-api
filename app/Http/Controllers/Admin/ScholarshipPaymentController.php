<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Scholarship\BulkPayAction;
use App\Http\Controllers\Controller;
use App\Models\ScholarshipPaymentBatch;
use App\Models\ScholarshipRefrend;
use App\Services\Scholarship\BankDataValidator;
use App\Services\Scholarship\BankPaymentFileName;
use App\Services\Scholarship\BankPaymentFileSerializer;
use App\Services\Scholarship\PaymentBatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * NOT to be confused with ScholarshipPaymentDataController (bank-account /
 * CURP / RFC configuration for a single becario, under
 * `admin/scholarship-payment-data`). This controller is the batch REVIEW +
 * PROCESS surface for a whole generación+sede+período payroll run, under
 * `admin/scholarship-payments` (design D6 — the near-collision between these
 * two names is intentional/accepted, mitigated by this docblock).
 *
 * index()    -> pre-payment readiness list + summary for a batch key.
 * document() -> single-becario payment document (matrícula, incidencias,
 *               retenciones, comentarios, desglose de monto).
 * process()  -> the money gate: re-derives batch membership from the key
 *               (NEVER trusts client-supplied ids, design D2), rejects the
 *               whole batch (422) if any row is not payable, rejects (409)
 *               if the client's expected_count/expected_total are stale, and
 *               only then creates the ScholarshipPaymentBatch row and calls
 *               BulkPayAction — one atomic, all-or-nothing operation.
 */
class ScholarshipPaymentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'generation_id' => 'required|integer',
            'campus'        => 'required|string|max:50',
            'period_year'   => 'required|integer|min:2020|max:2100',
            'period_month'  => 'required|integer|min:1|max:12',
        ]);

        $service = app(PaymentBatchService::class);
        $rows    = $service->rows(
            (int) $data['generation_id'],
            $data['campus'],
            (int) $data['period_year'],
            (int) $data['period_month']
        );
        $summary = $service->summary($rows);

        return response()->json([
            'res'  => true,
            'data' => [
                'rows'    => $rows,
                'summary' => $summary,
                'batch'   => $this->batchBlock($rows),
            ],
        ]);
    }

    /**
     * Derives whether this batch key is already paid from the row-level
     * `payment_batch_id` PaymentBatchService::rows() already SELECTs — zero
     * additional queries (design D3, sdd/becario-payment-bank-file-export).
     * Without this, the export feature is unreachable after a page reload:
     * the SPA's `paymentsStore.batchId` is only ever set transiently by
     * process(), never recovered on a fresh index() call.
     *
     * `batch_id` is null unless the rows carry EXACTLY ONE distinct
     * non-null payment_batch_id — 0 means unpaid, >1 is impossible given the
     * batch key's unique index, but is handled explicitly rather than
     * picking one arbitrarily.
     *
     * @param array<int, array{payment_batch_id: ?int}> $rows
     * @return array{batch_id: ?int, is_paid: bool}
     */
    private function batchBlock(array $rows): array
    {
        $paymentBatchIds = collect($rows)->pluck('payment_batch_id')->filter()->unique();

        if ($paymentBatchIds->count() !== 1) {
            return ['batch_id' => null, 'is_paid' => false];
        }

        return ['batch_id' => $paymentBatchIds->first(), 'is_paid' => true];
    }

    /**
     * Single-becario payment document. Assembled from ScholarshipRefrend +
     * its user (matrícula) + its incidents relation — no separate read model
     * exists for this yet (this endpoint is new in this PR), so the shape is
     * decided here: matrícula, nombre, incidencias, meses retenidos
     * (carryover_months_count/detail), % retenido (carryover_percentage),
     * the 3 comentario fields kept SEPARATE (atencion_observations,
     * pedagogia_observations, resolution_notes — spec's resolved decision,
     * never merged into one free-text blob), and a monto breakdown.
     */
    public function document(ScholarshipRefrend $refrend): JsonResponse
    {
        $refrend->loadMissing(['user', 'incidents']);

        return response()->json([
            'res'  => true,
            'data' => [
                'refrend_id'     => $refrend->id,
                'user_id'        => $refrend->user_id,
                'enrollment'     => $refrend->user?->enrollment,
                'snapshot_name'  => $refrend->snapshot_name,
                'incidents'      => $refrend->incidents->map(fn ($incident) => [
                    'id'                 => $incident->id,
                    'incident_category'  => $incident->incident_category,
                    'incident_type'      => $incident->incident_type,
                    'description'        => $incident->description,
                    'is_resolved'        => $incident->is_resolved,
                ])->values()->all(),
                'carryover_months_count'  => $refrend->carryover_months_count,
                'carryover_months_detail' => $refrend->carryover_months_detail,
                'carryover_percentage'    => $refrend->carryover_percentage,
                'atencion_observations'   => $refrend->atencion_observations,
                'pedagogia_observations'  => $refrend->pedagogia_observations,
                'resolution_notes'        => $refrend->resolution_notes,
                'amount_breakdown' => [
                    'base_amount'                   => $refrend->base_amount,
                    'discount_percentage'           => $refrend->discount_percentage,
                    'discount_amount'                => $refrend->discount_amount,
                    'amount_pending_from_previous'  => $refrend->amount_pending_from_previous,
                    'refund_amount_from_previous'    => $refrend->refund_amount_from_previous,
                    'final_amount'                   => $refrend->final_amount,
                    'total_to_pay'                   => $refrend->total_to_pay,
                ],
            ],
        ]);
    }

    /**
     * The all-or-nothing money gate (design D2). The client posts the batch
     * KEY plus what it believes the batch's count/total are — never ids[].
     * Membership is re-derived here, under no lock yet (the row lock happens
     * inside BulkPayAction), so a client cannot craft a partial-batch
     * request. `expected_total` is compared as a number_format(...,2) STRING
     * against PaymentBatchService::summary()'s total_amount (also a
     * number_format string) — never as floats, since MySQL returns decimal
     * sums as strings and SQLite as floats (the exact trap
     * RefrendBulkQueryService.php:162-173 already documents).
     */
    public function process(Request $request): JsonResponse
    {
        $data = $request->validate([
            'generation_id'  => 'required|integer',
            'campus'         => 'required|string|max:50',
            'period_year'    => 'required|integer|min:2020|max:2100',
            'period_month'   => 'required|integer|min:1|max:12',
            'expected_count' => 'required|integer|min:0',
            'expected_total' => 'required|numeric',
        ]);

        $service = app(PaymentBatchService::class);
        $rows    = $service->rows(
            (int) $data['generation_id'],
            $data['campus'],
            (int) $data['period_year'],
            (int) $data['period_month']
        );
        $summary = $service->summary($rows);

        $blockingRows = array_values(array_filter($rows, fn (array $row) => !$row['is_payable']));

        if (!empty($blockingRows)) {
            return response()->json([
                'res'  => false,
                'msg'  => 'El lote tiene becarios que no están listos para pago.',
                'data' => ['blocking_rows' => $blockingRows],
            ], 422);
        }

        $expectedTotal = number_format((float) $data['expected_total'], 2, '.', '');

        if ((int) $data['expected_count'] !== count($rows) || $expectedTotal !== $summary['total_amount']) {
            return response()->json([
                'res'  => false,
                'msg'  => 'El lote cambió desde que se cargó la pantalla. Vuelve a cargarla.',
                'data' => [
                    'count'        => count($rows),
                    'total_amount' => $summary['total_amount'],
                ],
            ], 409);
        }

        $batch = ScholarshipPaymentBatch::create([
            'generation_id'   => $data['generation_id'],
            'campus'          => $data['campus'],
            'period_year'     => $data['period_year'],
            'period_month'    => $data['period_month'],
            'refrend_count'   => count($rows),
            'total_amount'    => $summary['total_amount'],
            'processed_by_id' => auth()->id(),
            'processed_at'    => now(),
        ]);

        $ids    = array_column($rows, 'refrend_id');
        $result = app(BulkPayAction::class)->execute($ids, auth()->id(), $batch);

        $skippedById = collect($result['errors'])->keyBy('id');

        $outcomeRows = array_map(function (array $row) use ($skippedById) {
            $skip                   = $skippedById->get($row['refrend_id']);
            $row['outcome']         = $skip ? 'SKIPPED' : 'PAID';
            $row['outcome_reason']  = $skip['reason'] ?? null;

            return $row;
        }, $rows);

        return response()->json([
            'res'  => true,
            'data' => [
                'batch_id' => $batch->id,
                'rows'     => $outcomeRows,
            ],
        ]);
    }

    /**
     * On-screen pre-flight for the bank-file export (design D4): count,
     * total, and the exact filename the file endpoint will use — computed
     * from the SAME `PaymentBatchService::paidRows()` + `BankDataValidator`
     * gate as export(), so this response is never a promise export() can
     * fail to keep (design's "Summary equals file" scenario).
     */
    public function exportSummary(ScholarshipPaymentBatch $batch): JsonResponse
    {
        $rows = $this->paidAndBankValidatedRows($batch);

        if ($rows instanceof JsonResponse) {
            return $rows;
        }

        $totalAmount = array_reduce(
            $rows,
            fn (float $carry, array $row) => $carry + (float) $row['total_to_pay'],
            0.0
        );

        return response()->json([
            'res'  => true,
            'data' => [
                'count'        => count($rows),
                'total_amount' => number_format($totalAmount, 2, '.', ''),
                'filename'     => BankPaymentFileName::forBatch($batch),
            ],
        ]);
    }

    /**
     * Streams the BBVA fixed-width bank file for an already-paid batch
     * (spec: "Export blocks entirely on any malformed row"). Re-runs the
     * SAME gate exportSummary() runs — never trusts the pre-flight call, so
     * the all-or-nothing invariant is a server-side guarantee, not a UI
     * convention (design D4).
     *
     * `streamDownload()` (Laravel core, design D6) is used instead of
     * `Excel::download()`/`Pdf::download()` because neither can emit
     * fixed-width plain text — first plain-text download in this codebase.
     */
    public function export(ScholarshipPaymentBatch $batch)
    {
        $rows = $this->paidAndBankValidatedRows($batch);

        if ($rows instanceof JsonResponse) {
            return $rows;
        }

        $serializer = app(BankPaymentFileSerializer::class);
        $filename   = BankPaymentFileName::forBatch($batch);

        return response()->streamDownload(
            function () use ($serializer, $rows) {
                echo $serializer->serialize($rows);
            },
            $filename,
            ['Content-Type' => 'text/plain']
        );
    }

    /**
     * Shared gate for both export endpoints (design D4's invariant: one code
     * path, two callers). Returns the payable rows on success, or a fully
     * formed 422 JsonResponse the caller returns verbatim on failure — never
     * a partial result.
     *
     * @return array<int, array{refrend_id: int, user_id: int, snapshot_name: string, rfc: ?string, account_number: ?string, total_to_pay: string}>|JsonResponse
     */
    private function paidAndBankValidatedRows(ScholarshipPaymentBatch $batch)
    {
        $rows = app(PaymentBatchService::class)->paidRows($batch);

        if (empty($rows)) {
            return response()->json([
                'res' => false,
                'msg' => 'El lote no tiene becarios pagados.',
            ], 422);
        }

        $validator   = app(BankDataValidator::class);
        $invalidRows = [];

        foreach ($rows as $row) {
            $reasons = $validator->validate($row['account_number'], $row['rfc'], $row['total_to_pay']);

            if (!empty($reasons)) {
                $invalidRows[] = [
                    'refrend_id'     => $row['refrend_id'],
                    'snapshot_name'  => $row['snapshot_name'],
                    'account_number' => $row['account_number'],
                    'rfc'            => $row['rfc'],
                    'reasons'        => $reasons,
                ];
            }
        }

        if (!empty($invalidRows)) {
            return response()->json([
                'res'  => false,
                'msg'  => 'El lote tiene becarios con datos bancarios inválidos.',
                'data' => ['invalid_rows' => $invalidRows],
            ], 422);
        }

        return $rows;
    }
}
