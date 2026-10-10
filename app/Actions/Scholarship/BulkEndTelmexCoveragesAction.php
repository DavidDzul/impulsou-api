<?php

namespace App\Actions\Scholarship;

use App\Models\TelmexCoverage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * sdd/telmex-cobertura-lote, PR1 design D1-D4. Bulk variant of
 * EndTelmexCoverageAction — processes each coverage_id independently so a
 * failure on one never rolls back or blocks the others. Delegates the
 * actual end-of-coverage rules to the unchanged EndTelmexCoverageAction;
 * its inner DB::transaction becomes a savepoint inside this action's
 * per-id outer transaction.
 */
class BulkEndTelmexCoveragesAction
{
    public function __construct(private EndTelmexCoverageAction $endAction) {}

    /**
     * @param  array<int, int>  $ids
     * @param  array<string, mixed>  $data  ['telmex_start_period' => ...]
     * @return array{ended: array<int, array{coverage_id: int, user_id: int}>, failed: array<int, array{coverage_id: int, user_id: int|null, reason: string}>}
     */
    public function execute(array $ids, array $data, int $userId): array
    {
        $sortedIds = collect($ids)->unique()->sort()->values();

        // Preload once (D3): 1 query regardless of batch size. Ids missing
        // from this map are reported as "not found" without ever opening a
        // transaction for them.
        $preloaded = TelmexCoverage::query()
            ->whereIn('id', $sortedIds)
            ->get(['id', 'user_id'])
            ->keyBy('id');

        $ended  = [];
        $failed = [];

        foreach ($sortedIds as $id) {
            $preview = $preloaded->get($id);

            if ($preview === null) {
                $failed[] = ['coverage_id' => $id, 'user_id' => null, 'reason' => 'Cobertura no encontrada'];

                continue;
            }

            try {
                $coverage = DB::transaction(function () use ($id, $data, $userId) {
                    $locked = TelmexCoverage::lockForUpdate()->findOrFail($id);

                    if ($locked->status !== 'ACTIVA') {
                        throw new \DomainException('Ya no está activa');
                    }

                    return $this->endAction->execute($locked, $data, $userId);
                });

                $ended[] = ['coverage_id' => $id, 'user_id' => $coverage->user_id];
            } catch (\DomainException $e) {
                $failed[] = ['coverage_id' => $id, 'user_id' => $preview->user_id, 'reason' => $e->getMessage()];
            } catch (\Throwable $e) {
                Log::error('BulkEndTelmexCoveragesAction: unexpected failure ending coverage', [
                    'coverage_id' => $id,
                    'exception'   => $e,
                ]);

                $failed[] = ['coverage_id' => $id, 'user_id' => $preview->user_id, 'reason' => 'Error inesperado'];
            }
        }

        return ['ended' => $ended, 'failed' => $failed];
    }
}
