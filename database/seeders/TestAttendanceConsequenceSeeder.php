<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Crea 2 becarios con consecuencias de asistencia para el semestre ago-dic 2026:
 *
 *  1. Roberto Díaz     — 2 retardos en agosto → has_retardos_discount = true
 *  2. Sandra Morales   — 1 falta en septiembre → month_absent = 1 → has_falta_discount = true
 *
 * También crea 3 clases en el semestre ago-dic 2026 si no existen.
 */
class TestAttendanceConsequenceSeeder extends Seeder
{
    private const CAMPUS        = 'MERIDA';
    private const GENERATION_ID = 1;
    private const MONTHLY       = '1200.00';

    public function run(): void
    {
        $today    = now()->startOfDay();
        $thisMonth = $today->copy()->startOfMonth();
        $prevMonth = $today->copy()->subMonth()->startOfMonth();
        $twoMonths = $today->copy()->subMonths(2)->startOfMonth();

        // Fechas dinámicas: siempre pasadas respecto a hoy
        $dates = [
            $twoMonths->copy()->addDays(9)->toDateString(),  // hace 2 meses — retardo 1
            $twoMonths->copy()->addDays(23)->toDateString(), // hace 2 meses — retardo 2
            $prevMonth->copy()->addDays(9)->toDateString(),  // mes pasado   — falta
        ];

        $classIds = $this->ensureClasses($dates);
        $this->command->line("Clases creadas/encontradas: " . implode(', ', $classIds));

        // Verificar que todas las clases son pasadas
        foreach ($dates as $i => $date) {
            if ($date >= $today->toDateString()) {
                $this->command->error("Clase {$date} no es pasada — ajustá las fechas del seeder.");
                return;
            }
            $this->command->line("  Clase {$classIds[$i]}: {$date} ✓ pasada");
        }

        // ── Becario 1: 2 retardos acumulados ─────────────────────────────────
        $roberto = $this->ensureUser('Roberto', 'Díaz', 'roberto.diaz@test.iu');
        $this->ensureAttendances($roberto, [
            $classIds[0] => 'LATE',    // retardo 1
            $classIds[1] => 'LATE',    // retardo 2 → dispara descuento
            $classIds[2] => 'PRESENT',
        ]);
        $this->command->line("  ✓ Roberto Díaz (user_id={$roberto}) — 2 retardos acumulados");

        // ── Becario 2: falta injustificada en el mes pasado ───────────────────
        $sandra = $this->ensureUser('Sandra', 'Morales', 'sandra.morales@test.iu');
        $this->ensureAttendances($sandra, [
            $classIds[0] => 'PRESENT',
            $classIds[1] => 'PRESENT',
            $classIds[2] => 'ABSENT',  // falta injustificada
        ]);
        $this->command->line("  ✓ Sandra Morales (user_id={$sandra}) — 1 falta injustificada");

        $this->command->info('TestAttendanceConsequenceSeeder completado.');
        $this->command->info("Generá refrendos {$prevMonth->format('m-Y')} (G.19, MERIDA) para ver las consecuencias.");
    }

    private function ensureClasses(array $dates): array
    {
        $ids = [];
        foreach ($dates as $date) {
            $existing = DB::table('classes')->where('date', $date)->value('id');
            if ($existing) {
                $ids[] = $existing;
                continue;
            }
            $label = \Carbon\Carbon::parse($date)->translatedFormat('d \d\e F Y');
            $ids[] = DB::table('classes')->insertGetId([
                'name'          => "Formación Integral — {$label}",
                'generation_id' => self::GENERATION_ID,
                'campus'        => self::CAMPUS,
                'date'          => $date,
                'start_time'    => '09:00:00',
                'end_time'      => '11:00:00',
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        }
        return $ids;
    }

    private function ensureUser(string $first, string $last, string $email): int
    {
        $existing = DB::table('users')->where('email', $email)->value('id');
        if ($existing) return $existing;

        $userId = DB::table('users')->insertGetId([
            'first_name'    => $first,
            'last_name'     => $last,
            'email'         => $email,
            'password'      => Hash::make('password'),
            'campus'        => self::CAMPUS,
            'generation_id' => self::GENERATION_ID,
            'user_type'     => 'BEC_ACTIVE',
            'phone'         => '9990000001',
            'active'        => true,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $role = DB::table('roles')->where('name', 'BASIC')->first();
        if ($role) {
            DB::table('model_has_roles')->insert([
                'role_id'    => $role->id,
                'model_type' => 'App\\Models\\User',
                'model_id'   => $userId,
            ]);
        }

        DB::table('scholarship_profiles')->insert([
            'user_id'             => $userId,
            'scholarship_type'    => 'IU',
            'monthly_amount'      => self::MONTHLY,
            'reticula_start_date' => '2024-01-01',
            'reticula_end_date'   => '2028-01-01',
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        return $userId;
    }

    private function ensureAttendances(int $userId, array $classAttendances): void
    {
        $today = now()->toDateString();

        foreach ($classAttendances as $classId => $code) {
            // Nunca insertar asistencias para clases futuras
            $classDate = DB::table('classes')->where('id', $classId)->value('date');
            if ($classDate >= $today) continue;

            $exists = DB::table('attendances')
                ->where('user_id', $userId)
                ->where('class_id', $classId)
                ->exists();

            if ($exists) continue;

            [$status, $checkIn, $checkOut] = match ($code) {
                'PRESENT' => ['PRESENT', '09:02:00', '11:00:00'],
                'LATE'    => ['LATE',    '09:22:00', '11:00:00'],
                'ABSENT'  => ['ABSENT',  null,       null      ],
                default   => ['PRESENT', '09:02:00', '11:00:00'],
            };

            DB::table('attendances')->insert([
                'user_id'               => $userId,
                'class_id'              => $classId,
                'check_in'              => $checkIn,
                'check_out'             => $checkOut,
                'status'                => $status,
                'class_status'          => 'COMPLETED',
                'observations'          => null,
                'late_penalty_consumed' => false,
                'late_penalty_consumed_refrend_id' => null,
                'created_at'            => now(),
                'updated_at'            => now(),
            ]);
        }
    }
}
