<?php

namespace App\Services\Scholarship;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Inclusive date range [semester start of reference date, reference date]
 * used to evaluate attendance for a refrend. Semesters: Jan 1 - Jul 31 and
 * Aug 1 - Dec 31. Bounds are Y-m-d strings.
 */
final class AttendanceWindow
{
    public function __construct(
        public readonly string $start,
        public readonly string $end,
    ) {
    }

    public static function endingAt(CarbonInterface $reference): self
    {
        $startMonth = $reference->month <= 7 ? 1 : 8;

        return new self(
            Carbon::create($reference->year, $startMonth, 1)->toDateString(),
            $reference->toDateString(),
        );
    }
}
