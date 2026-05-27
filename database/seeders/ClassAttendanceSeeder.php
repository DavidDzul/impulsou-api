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
        $poolIndex    = 0;

        $current  = Carbon::parse(self::START);
        $end      = Carbon::parse(self::END);
        $classDays = [Carbon::MONDAY, Carbon::WEDNESDAY, Carbon::FRIDAY];

        $classCount      = 0;
        $attendanceCount = 0;

        while ($current->lte($end)) {
            if (in_array($current->dayOfWeek, $classDays)) {
                $classId = DB::table('classes')->insertGetId([
                    'name'          => 'Formación Integral — ' . $current->translatedFormat('d \d\e F Y'),
                    'date'          => $current->format('Y-m-d'),
                    'start_time'    => '09:00:00',
                    'end_time'      => '11:00:00',
                    'campus'        => self::CAMPUS,
                    'generation_id' => $generationId,
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);
                $classCount++;

                $status = $pool[$poolIndex % count($pool)];
                $poolIndex++;

                [$checkIn, $checkOut, $latePenaltyConsumed] = $this->checkInOutFor($status);

                DB::table('attendances')->insert([
                    'user_id'                          => self::USER_ID,
                    'class_id'                         => $classId,
                    'check_in'                         => $checkIn,
                    'check_out'                        => $checkOut,
                    'status'                           => $status,
                    'class_status'                     => 'COMPLETED',
                    'observations'                     => null,
                    'late_penalty_consumed'            => $latePenaltyConsumed,
                    'late_penalty_consumed_refrend_id' => null,
                    'created_at'                       => now(),
                    'updated_at'                       => now(),
                ]);
                $attendanceCount++;
            }

            $current->addDay();
        }

        $this->command->info("Seeded {$classCount} classes and {$attendanceCount} attendances for user_id " . self::USER_ID . '.');
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
        $latePenaltyConsumed = false;

        switch ($status) {
            case 'PRESENT':
                $checkIn  = '09:02:00';
                $checkOut = '11:00:00';
                break;

            case 'LATE':
                $minutes  = rand(10, 45);
                $checkIn  = sprintf('09:%02d:00', $minutes);
                $checkOut = '11:00:00';
                // Randomly mark ~half of LATE records as already consumed
                $latePenaltyConsumed = (bool) rand(0, 1);
                break;

            case 'JUSTIFIED_LATE':
                $minutes  = rand(10, 30);
                $checkIn  = sprintf('09:%02d:00', $minutes);
                $checkOut = '11:00:00';
                break;

            // ABSENT and JUSTIFIED_ABSENCE: no check-in/out
        }

        return [$checkIn, $checkOut, $latePenaltyConsumed];
    }
}
