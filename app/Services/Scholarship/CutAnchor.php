<?php

namespace App\Services\Scholarship;

use App\Models\ScholarshipRefrend;
use Carbon\CarbonInterface;

/**
 * sdd/retenciones-pendientes: the month from which a becario's advance
 * payments are cancelled (that month INCLUDED). For a manual cut or the
 * automatic egreso it is the cut refrend's period; for a user deactivation
 * there is no refrend, so it is the current month (cutRefrendId = null).
 */
final class CutAnchor
{
    public function __construct(
        public readonly int $userId,
        public readonly int $year,
        public readonly int $month,
        public readonly ?int $cutRefrendId
    ) {
    }

    public static function fromRefrend(ScholarshipRefrend $refrend): self
    {
        return new self(
            (int) $refrend->user_id,
            (int) $refrend->period_year,
            (int) $refrend->period_month,
            $refrend->id !== null ? (int) $refrend->id : null
        );
    }

    public static function forDeactivation(int $userId, CarbonInterface $now): self
    {
        return new self($userId, (int) $now->year, (int) $now->month, null);
    }
}
