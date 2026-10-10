<?php

namespace App\Actions\Scholarship;

use App\Models\TelmexCoverage;
use App\Services\Scholarship\TelmexCoverageLedger;
use App\Services\Scholarship\TelmexCoverageRefrendSync;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * sdd/telmex-cobertura-iu, design D8 + tasks 3a.5. Staff marks "Telmex
 * empezó a pagar": end_period = telmex_start_period - 1 month (the last
 * month IU still advances). Must be >= start_period and >= the last
 * already-paid covered month (closing the window before money that was
 * already disbursed would corrupt the debt/ledger history).
 */
class EndTelmexCoverageAction
{
    public function __construct(
        private TelmexCoverageRefrendSync $sync,
        private TelmexCoverageLedger $ledger
    ) {}

    public function execute(TelmexCoverage $coverage, array $data, int $userId): TelmexCoverage
    {
        $telmexStart = Carbon::parse($data['telmex_start_period'])->startOfMonth();
        $endPeriod   = $telmexStart->copy()->subMonth();

        return DB::transaction(function () use ($coverage, $endPeriod, $userId) {
            $coverage = TelmexCoverage::lockForUpdate()->findOrFail($coverage->id);

            if (in_array($coverage->status, ['CANCELADA', 'LIQUIDADA'], true)) {
                throw new \DomainException('Esta cobertura ya está cerrada; no se puede registrar el fin de cobertura.');
            }

            $startPeriod = $coverage->start_period->copy()->startOfMonth();
            if ($endPeriod->lt($startPeriod)) {
                throw new \DomainException(
                    'El mes en que Telmex empezó a pagar no puede ser anterior al inicio de la cobertura.'
                );
            }

            $lastPaidAbsolute = $this->ledger->lastPaidCoveredPeriodAbsolute($coverage);
            $endAbsolute      = $endPeriod->year * 12 + $endPeriod->month;
            if ($lastPaidAbsolute !== null && $endAbsolute < $lastPaidAbsolute) {
                throw new \DomainException(
                    'El fin de cobertura no puede ser anterior al último mes cubierto que ya fue pagado.'
                );
            }

            $coverage->update([
                'end_period'  => $endPeriod,
                'ended_by_id' => $userId,
                'ended_at'    => now(),
            ]);

            $this->sync->sync($coverage->fresh());

            return $this->ledger->recomputeStatus($coverage->fresh());
        });
    }
}
