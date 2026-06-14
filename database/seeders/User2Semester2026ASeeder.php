<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class User2Semester2026ASeeder extends Seeder
{
    private const USER_ID = 2;

    private const CLASSES = [
        ['date' => '2026-01-15', 'name' => 'Sesión enero 2026',  'status' => 'PRESENT'],
        ['date' => '2026-02-12', 'name' => 'Sesión febrero 2026', 'status' => 'PRESENT'],
        ['date' => '2026-03-19', 'name' => 'Sesión marzo 2026',  'status' => 'PRESENT'],
        ['date' => '2026-04-16', 'name' => 'Sesión abril 2026',  'status' => 'PRESENT'],
        ['date' => '2026-05-14', 'name' => 'Sesión mayo 2026',   'status' => 'PRESENT'],
        ['date' => '2026-06-11', 'name' => 'Sesión junio 2026',  'status' => 'PRESENT'],
    ];

    public function run(): void
    {
        $user = DB::table('users')->where('id', self::USER_ID)->first();

        if (! $user) {
            $this->command->error('User ID ' . self::USER_ID . ' not found.');
            return;
        }

        if (! $user->generation_id) {
            $this->command->error('User ID ' . self::USER_ID . ' has no generation_id assigned.');
            return;
        }

        $this->command->info("Seeding 6 classes for user_id=" . self::USER_ID . " ({$user->campus}, generation_id={$user->generation_id})");

        foreach (self::CLASSES as $entry) {
            $classId = $this->resolveClass($entry['date'], $entry['name'], $user);
            $this->resolveAttendance($classId, $entry['status']);
        }

        $this->command->info('User2Semester2026ASeeder completed.');
    }

    private function resolveClass(string $date, string $name, object $user): int
    {
        $existing = DB::table('classes')
            ->where('date', $date)
            ->where('campus', $user->campus)
            ->where('generation_id', $user->generation_id)
            ->value('id');

        if ($existing) {
            $this->command->line("  → Class {$date} already exists (id={$existing}), reusing.");
            return $existing;
        }

        $id = DB::table('classes')->insertGetId([
            'name'          => $name,
            'date'          => $date,
            'start_time'    => '09:00:00',
            'end_time'      => '11:00:00',
            'campus'        => $user->campus,
            'generation_id' => $user->generation_id,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $this->command->line("  ✓ Class created: {$name} on {$date} (id={$id})");
        return $id;
    }

    private function resolveAttendance(int $classId, string $status): void
    {
        $exists = DB::table('attendances')
            ->where('user_id', self::USER_ID)
            ->where('class_id', $classId)
            ->exists();

        if ($exists) {
            $this->command->line("  → Attendance for class_id={$classId} already exists, skipping.");
            return;
        }

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

        $this->command->line("  ✓ Attendance created for class_id={$classId} ({$status})");
    }

    private function checkInOutFor(string $status): array
    {
        return match ($status) {
            'PRESENT'           => ['09:02:00', '11:00:00'],
            'LATE'              => ['09:22:00', '11:00:00'],
            'JUSTIFIED_LATE'    => ['09:15:00', '11:00:00'],
            'ABSENT'            => [null,        null       ],
            'JUSTIFIED_ABSENCE' => [null,        null       ],
            default             => ['09:02:00', '11:00:00'],
        };
    }
}
