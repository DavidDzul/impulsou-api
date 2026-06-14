<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ClassAttendanceSeeder extends Seeder
{
    private const USER_ID  = 2;
    private const CAMPUS   = 'MERIDA';
    private const START    = '2026-01-05'; // first Monday Jan 2026
    private const END      = '2026-07-31';

    /**
     * Set to an integer to distribute N classes evenly across the semester.
     * Useful for dev/testing: some classes land before today, some after,
     * so the date <= CURDATE() boundary can be verified.
     * Null = full MWF schedule (~90 classes).
     */
    private ?int $maxClasses = 10;

    /** Weighted status pool: keys = status, values = relative weight */
    private const STATUS_WEIGHTS = [
        'PRESENT'           => 68,
        'LATE'              => 14,
        'ABSENT'            => 12,
        'JUSTIFIED_ABSENCE' => 4,
        'JUSTIFIED_LATE'    => 2,
    ];

    public function run(): void
    {
        $generationId = $this->resolveGeneration();
        $pool         = $this->buildStatusPool();

        if ($this->maxClasses !== null) {
            $this->runLimited($generationId, $pool);
        } else {
            $this->runFull($generationId, $pool);
        }
    }

    /**
     * Distribute $maxClasses evenly from START to END so that some fall
     * before today and some after — ideal for testing the CURDATE() cutoff.
     */
    private function runLimited(int $generationId, array $pool): void
    {
        $start     = Carbon::parse(self::START);
        $end       = Carbon::parse(self::END);
        $totalDays = (int) $start->diffInDays($end);
        $step      = (int) floor($totalDays / max($this->maxClasses - 1, 1));

        $classCount      = 0;
        $attendanceCount = 0;

        for ($i = 0; $i < $this->maxClasses; $i++) {
            $date = $start->copy()->addDays($i * $step);

            // Advance to next weekday if the calculated date lands on a weekend.
            while ($date->isWeekend()) {
                $date->addDay();
            }

            // Stop if we've gone past the semester end.
            if ($date->gt($end)) {
                break;
            }

            $classId = $this->insertClass($date, $generationId);
            $classCount++;

            $status = $pool[$i % count($pool)];
            $this->insertAttendance($classId, $status);
            $attendanceCount++;

            $label = $date->toDateString() <= now()->toDateString() ? '[past]' : '[future]';
            $this->command->line("  {$date->toDateString()} {$label} — {$status}");
        }

        $this->command->info("Seeded {$classCount} classes and {$attendanceCount} attendances (limited mode) for user_id " . self::USER_ID . '.');
    }

    /** Full MWF schedule across the entire semester. */
    private function runFull(int $generationId, array $pool): void
    {
        $current   = Carbon::parse(self::START);
        $end       = Carbon::parse(self::END);
        $classDays = [Carbon::MONDAY, Carbon::WEDNESDAY, Carbon::FRIDAY];
        $poolIndex = 0;

        $classCount      = 0;
        $attendanceCount = 0;

        while ($current->lte($end)) {
            if (in_array($current->dayOfWeek, $classDays)) {
                $classId = $this->insertClass($current, $generationId);
                $classCount++;

                $status = $pool[$poolIndex % count($pool)];
                $poolIndex++;

                $this->insertAttendance($classId, $status);
                $attendanceCount++;
            }

            $current->addDay();
        }

        $this->command->info("Seeded {$classCount} classes and {$attendanceCount} attendances (full mode) for user_id " . self::USER_ID . '.');
    }

    private function insertClass(Carbon $date, int $generationId): int
    {
        return DB::table('classes')->insertGetId([
            'name'          => 'Formación Integral — ' . $date->translatedFormat('d \d\e F Y'),
            'date'          => $date->format('Y-m-d'),
            'start_time'    => '09:00:00',
            'end_time'      => '11:00:00',
            'campus'        => self::CAMPUS,
            'generation_id' => $generationId,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    private function insertAttendance(int $classId, string $status): void
    {
        [$checkIn, $checkOut] = $this->checkInOutFor($status);

        DB::table('attendances')->insert([
            'user_id'      => self::USER_ID,
            'class_id'     => $classId,
            'check_in'     => $checkIn,
            'check_out'    => $checkOut,
            'status'       => $status,
            'class_status' => 'COMPLETED',
            'observations' => null,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    private function resolveGeneration(): int
    {
        $gen = DB::table('generations')
            ->where('campus', self::CAMPUS)
            ->orderByDesc('id')
            ->first();

        if ($gen) {
            $this->command->info("Using existing generation #{$gen->id}: {$gen->generation_name}");
            return $gen->id;
        }

        $id = DB::table('generations')->insertGetId([
            'generation_name'   => 'Generación ENE-JUL 2026',
            'campus'            => self::CAMPUS,
            'generation_active' => true,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
        $this->command->info("Created new generation #{$id} for campus " . self::CAMPUS);
        return $id;
    }

    private function buildStatusPool(): array
    {
        $pool = [];
        foreach (self::STATUS_WEIGHTS as $status => $weight) {
            for ($i = 0; $i < $weight; $i++) {
                $pool[] = $status;
            }
        }
        shuffle($pool);
        return $pool;
    }

    private function checkInOutFor(string $status): array
    {
        $checkIn  = null;
        $checkOut = null;

        switch ($status) {
            case 'PRESENT':
                $checkIn  = '09:02:00';
                $checkOut = '11:00:00';
                break;

            case 'LATE':
                $minutes  = rand(10, 45);
                $checkIn  = sprintf('09:%02d:00', $minutes);
                $checkOut = '11:00:00';
                break;

            case 'JUSTIFIED_LATE':
                $minutes  = rand(10, 30);
                $checkIn  = sprintf('09:%02d:00', $minutes);
                $checkOut = '11:00:00';
                break;
        }

        return [$checkIn, $checkOut];
    }
}
