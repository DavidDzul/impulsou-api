<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Scholarship\ActivateTelmexCoverageAction;
use App\Actions\Scholarship\CancelTelmexCoverageAction;
use App\Actions\Scholarship\EndTelmexCoverageAction;
use App\Actions\Scholarship\ReactivateTelmexCoverageAction;
use App\Actions\Scholarship\RegisterTelmexCoveragePaymentAction;
use App\Actions\Scholarship\VoidTelmexCoveragePaymentAction;
use App\Enums\RefrendStatus;
use App\Enums\ScholarshipType;
use App\Http\Controllers\Controller;
use App\Http\Requests\CancelTelmexCoverageRequest;
use App\Http\Requests\EndTelmexCoverageRequest;
use App\Http\Requests\StoreTelmexCoveragePaymentRequest;
use App\Http\Requests\StoreTelmexCoverageRequest;
use App\Http\Requests\VoidTelmexCoveragePaymentRequest;
use App\Models\Generation;
use App\Models\ScholarshipProfile;
use App\Models\ScholarshipRefrend;
use App\Models\TelmexCoverage;
use App\Models\TelmexCoveragePayment;
use App\Models\User;
use App\Services\Scholarship\TelmexCoverageLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * sdd/telmex-cobertura-iu, design API table + tasks 3b.2. Thin HTTP layer
 * over the PR3a actions/ledger — every write delegates to its Action;
 * \DomainException from the domain layer maps to 422 here, same convention
 * as ScholarshipWithholdingController::voidPayment.
 */
class TelmexCoverageController extends Controller
{
    /**
     * Denormalized list (orchestrator-progress #1925 contract): each row
     * embeds becario_name/campus/generation plus the ledger-derived
     * advanced/repaid/balance/has_paid_covered_month, so the admin panel
     * never has to issue a second request per row.
     *
     * N+1 note: users + generations are batch-loaded (2 extra queries total
     * regardless of row count). The per-row ledger computation still issues
     * its own queries per coverage — no bulk ledger primitive exists yet
     * (TelmexCoverageLedger only exposes single-coverage methods). Given
     * this list is bounded by "becarios who were ever activated" (a small,
     * operationally-bounded set, not a full becario roster), this is an
     * accepted, flagged limitation rather than a new bulk-ledger service.
     */
    public function index(Request $request): JsonResponse
    {
        $query = TelmexCoverage::query();

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $coverages = $query->orderByDesc('created_at')->get();

        $data = $this->presentCoverages($coverages);

        if ($search = $request->input('search')) {
            $needle = mb_strtolower((string) $search);
            $data = $data->filter(
                fn (array $row) => str_contains(mb_strtolower((string) $row['becario_name']), $needle)
            )->values();
        }

        return response()->json(['res' => true, 'data' => $data]);
    }

    /**
     * decisions-3 #1926: excludes ANY becario with an existing coverage
     * row, including CANCELADA — one coverage per becario (UNIQUE user_id);
     * a cancelled coverage without paid months is reactivated via
     * ReactivateTelmexCoverageAction, never re-created through this list.
     */
    public function eligible(): JsonResponse
    {
        $existingUserIds = TelmexCoverage::query()->pluck('user_id');

        $profiles = ScholarshipProfile::query()
            ->whereIn('scholarship_type', [ScholarshipType::TELMEX->value, ScholarshipType::TELMEX_IU->value])
            ->whereNotIn('user_id', $existingUserIds)
            ->get(['user_id', 'scholarship_type']);

        [$users, $generations] = $this->loadUsersAndGenerations($profiles->pluck('user_id'));

        $data = $profiles->map(function (ScholarshipProfile $profile) use ($users, $generations) {
            $user = $users->get($profile->user_id);

            return [
                'id'               => $profile->user_id,
                'name'             => $this->fullName($user),
                'campus'           => $user?->campus,
                'generation'       => $this->generationName($user, $generations),
                'scholarship_type' => $profile->scholarship_type->value,
            ];
        })->values();

        return response()->json(['res' => true, 'data' => $data]);
    }

    public function show(TelmexCoverage $coverage): JsonResponse
    {
        $months = ScholarshipRefrend::query()
            ->where('snapshot_telmex_coverage_id', $coverage->id)
            ->orderBy('period_year')
            ->orderBy('period_month')
            ->get([
                'id', 'period_year', 'period_month', 'status',
                'payment_batch_id', 'snapshot_telmex_covered_amount', 'resolution_type',
                'snapshot_temporary_increase_amount',
            ])
            // has_temporary_increase: a covered month can also pay a
            // temporary increase. That increase is IU's own money and is NOT
            // part of the debt (the ledger only sums the covered Telmex part),
            // so the statement flags those months to explain why the deposit
            // was larger than what the becario must repay.
            // Shape consumed by administration-panel's CoverageStatement
            // (TelmexCoverageMonth). is_paid uses the same predicate as
            // TelmexCoverageLedger (batch OR status PAID), so a month marked
            // paid here is exactly a month counted in `advanced`.
            ->map(fn (ScholarshipRefrend $refrend) => [
                'id'                     => $refrend->id,
                'period'                 => sprintf('%04d-%02d-01', $refrend->period_year, $refrend->period_month),
                'covered_amount'         => (float) $refrend->snapshot_telmex_covered_amount,
                'is_paid'                => $refrend->payment_batch_id !== null
                    || $refrend->getRawOriginal('status') === RefrendStatus::PAID->value,
                'payment_batch_id'       => $refrend->payment_batch_id,
                'resolution_type'        => $refrend->resolution_type,
                'has_temporary_increase' => (float) ($refrend->snapshot_temporary_increase_amount ?? 0) > 0,
            ])
            ->values();

        $payments = $coverage->payments()
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (TelmexCoveragePayment $payment) => $this->serializePayment($payment))
            ->values();

        return response()->json(['res' => true, 'data' => [
            'coverage' => $this->presentCoverage($coverage),
            'months'   => $months,
            'payments' => $payments,
        ]]);
    }

    public function store(StoreTelmexCoverageRequest $request): JsonResponse
    {
        try {
            $coverage = app(ActivateTelmexCoverageAction::class)->execute($request->validated(), (int) auth()->id());
        } catch (\DomainException $e) {
            return response()->json(['res' => false, 'msg' => $e->getMessage()], 422);
        }

        return response()->json(['res' => true, 'data' => $this->presentCoverage($coverage)], 201);
    }

    public function end(EndTelmexCoverageRequest $request, TelmexCoverage $coverage): JsonResponse
    {
        try {
            $coverage = app(EndTelmexCoverageAction::class)
                ->execute($coverage, $request->validated(), (int) auth()->id());
        } catch (\DomainException $e) {
            return response()->json(['res' => false, 'msg' => $e->getMessage()], 422);
        }

        return response()->json(['res' => true, 'data' => $this->presentCoverage($coverage)]);
    }

    public function cancel(CancelTelmexCoverageRequest $request, TelmexCoverage $coverage): JsonResponse
    {
        try {
            // This Laravel version's FormRequest::validated() takes NO
            // arguments (always returns the full array) — passing a key is
            // silently ignored, not an error, so `(string) $request->validated('reason')`
            // would cast the WHOLE array to a string. Fetch the array once,
            // then index into it.
            $coverage = app(CancelTelmexCoverageAction::class)
                ->execute($coverage, (string) $request->validated()['reason'], (int) auth()->id());
        } catch (\DomainException $e) {
            return response()->json(['res' => false, 'msg' => $e->getMessage()], 422);
        }

        return response()->json(['res' => true, 'data' => $this->presentCoverage($coverage)]);
    }

    public function reactivate(TelmexCoverage $coverage): JsonResponse
    {
        try {
            $coverage = app(ReactivateTelmexCoverageAction::class)->execute($coverage, (int) auth()->id());
        } catch (\DomainException $e) {
            return response()->json(['res' => false, 'msg' => $e->getMessage()], 422);
        }

        return response()->json(['res' => true, 'data' => $this->presentCoverage($coverage)]);
    }

    public function storePayment(StoreTelmexCoveragePaymentRequest $request, TelmexCoverage $coverage): JsonResponse
    {
        try {
            $payment = app(RegisterTelmexCoveragePaymentAction::class)
                ->execute($coverage, $request->validated(), (int) auth()->id());
        } catch (\DomainException $e) {
            return response()->json(['res' => false, 'msg' => $e->getMessage()], 422);
        }

        return response()->json(['res' => true, 'data' => $this->serializePayment($payment)], 201);
    }

    /**
     * Mirrors ScholarshipWithholdingController::voidPayment's independent
     * path-param guard — {coverage} and {payment} are separate route
     * identifiers, so a mismatched combination must not silently void the
     * wrong ledger row.
     */
    public function voidPayment(
        VoidTelmexCoveragePaymentRequest $request,
        TelmexCoverage $coverage,
        TelmexCoveragePayment $payment
    ): JsonResponse {
        if ((int) $payment->coverage_id !== (int) $coverage->id) {
            return response()->json(['res' => false, 'msg' => 'Abono no encontrado.'], 404);
        }

        try {
            // Same FormRequest::validated() caveat as cancel() above — this
            // Laravel version ignores the key argument rather than erroring,
            // so it must be fetched as the full array first.
            $voided = app(VoidTelmexCoveragePaymentAction::class)
                ->execute($payment, (string) $request->validated()['void_reason'], (int) auth()->id());
        } catch (\DomainException $e) {
            return response()->json(['res' => false, 'msg' => $e->getMessage()], 422);
        }

        return response()->json(['res' => true, 'data' => $this->serializePayment($voided)]);
    }

    // ── Presentation helpers ─────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function presentCoverage(TelmexCoverage $coverage): array
    {
        [$users, $generations] = $this->loadUsersAndGenerations(collect([$coverage->user_id]));
        $user = $users->get($coverage->user_id);

        return array_merge($this->serializeCoverage($coverage, app(TelmexCoverageLedger::class)), [
            'becario_name' => $this->fullName($user),
            'campus'       => $user?->campus,
            'generation'   => $this->generationName($user, $generations),
        ]);
    }

    private function presentCoverages(Collection $coverages): Collection
    {
        [$users, $generations] = $this->loadUsersAndGenerations($coverages->pluck('user_id'));
        $ledger = app(TelmexCoverageLedger::class);

        return $coverages->map(function (TelmexCoverage $coverage) use ($users, $generations, $ledger) {
            $user = $users->get($coverage->user_id);

            return array_merge($this->serializeCoverage($coverage, $ledger), [
                'becario_name' => $this->fullName($user),
                'campus'       => $user?->campus,
                'generation'   => $this->generationName($user, $generations),
            ]);
        })->values();
    }

    /**
     * Explicit field mapping (not `$coverage->toArray()`): Eloquent's
     * default 'date'/'datetime' cast serialization emits a full
     * ISO-8601 timestamp (e.g. "2026-02-01T06:00:00.000000Z") for a plain
     * date column, which does not match the contract's "YYYY-MM-DD" shape
     * (orchestrator-progress #1925) — confirmed via tinker against this
     * model before writing this method.
     *
     * @return array<string, mixed>
     */
    private function serializeCoverage(TelmexCoverage $coverage, TelmexCoverageLedger $ledger): array
    {
        return [
            'id'                             => $coverage->id,
            'user_id'                         => $coverage->user_id,
            'scholarship_type_at_activation'  => $coverage->scholarship_type_at_activation,
            'status'                          => $coverage->status,
            'start_period'                    => $coverage->start_period->toDateString(),
            'end_period'                      => $coverage->end_period?->toDateString(),
            'notes'                           => $coverage->notes,
            'cancel_reason'                   => $coverage->cancel_reason,
            'activated_by_id'                 => $coverage->activated_by_id,
            'ended_by_id'                     => $coverage->ended_by_id,
            'ended_at'                        => $coverage->ended_at?->toDateTimeString(),
            'cancelled_by_id'                 => $coverage->cancelled_by_id,
            'cancelled_at'                    => $coverage->cancelled_at?->toDateTimeString(),
            'settled_at'                      => $coverage->settled_at?->toDateTimeString(),
            'created_at'                      => $coverage->created_at?->toDateTimeString(),
            'updated_at'                      => $coverage->updated_at?->toDateTimeString(),
            'advanced'                        => number_format($ledger->advanced($coverage), 2, '.', ''),
            'repaid'                          => number_format($ledger->repaid($coverage), 2, '.', ''),
            'balance'                         => number_format($ledger->balance($coverage), 2, '.', ''),
            'has_paid_covered_month'          => $ledger->hasPaidCoveredMonth($coverage),
        ];
    }

    /** @return array<string, mixed> */
    private function serializePayment(TelmexCoveragePayment $payment): array
    {
        return [
            'id'            => $payment->id,
            'coverage_id'   => $payment->coverage_id,
            'amount'        => $payment->amount,
            'paid_at'       => $payment->paid_at->toDateString(),
            'reference'     => $payment->reference,
            'notes'         => $payment->notes,
            'created_by_id' => $payment->created_by_id,
            'is_voided'     => $payment->is_voided,
            'voided_at'     => $payment->voided_at?->toDateTimeString(),
            'voided_by_id'  => $payment->voided_by_id,
            'void_reason'   => $payment->void_reason,
            'created_at'    => $payment->created_at?->toDateTimeString(),
            'updated_at'    => $payment->updated_at?->toDateTimeString(),
        ];
    }

    /**
     * Batch-loads users + generations for a set of user ids — 2 queries
     * total regardless of row count, avoiding N+1 on the denormalized
     * becario_name/campus/generation fields.
     *
     * @return array{0: Collection<int, User>, 1: Collection<int, Generation>}
     */
    private function loadUsersAndGenerations(Collection $userIds): array
    {
        $users = User::query()
            ->whereIn('id', $userIds->unique()->values())
            ->get(['id', 'first_name', 'last_name', 'campus', 'generation_id'])
            ->keyBy('id');

        $generations = Generation::query()
            ->whereIn('id', $users->pluck('generation_id')->filter()->unique()->values())
            ->get(['id', 'generation_name'])
            ->keyBy('id');

        return [$users, $generations];
    }

    private function fullName(?User $user): ?string
    {
        return $user ? trim($user->first_name.' '.$user->last_name) : null;
    }

    private function generationName(?User $user, Collection $generations): ?string
    {
        if ($user === null || $user->generation_id === null) {
            return null;
        }

        return $generations->get($user->generation_id)?->generation_name;
    }
}
