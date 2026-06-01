<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Crea 5 becarios de prueba con patrones de asistencia distintos
 * para verificar los indicadores de impacto en RefrendMasterTable.
 *
 * Escenarios:
 *  1. Ana García       — sin incidencias           → Impacto: Sin impacto
 *  2. Carlos López     — 1 falta en mayo           → Impacto: Falta
 *  3. María Torres     — 2 retardos en semestre    → Impacto: Retardos
 *  4. José Hernández   — falta en mayo + 2 ret.    → Impacto: Falta + Ret.
 *  5. Laura Martínez   — 1 retardo (sin penaliz.)  → Impacto: Sin impacto (chip amarillo en Ret.)
 *
 * Requiere que ClassAttendanceSeeder ya haya corrido (clases id 1-10 existentes).
 */
class TestBecarioSeeder extends Seeder
{
    private const CAMPUS        = 'MERIDA';
    private const GENERATION_ID = 1;
    private const MONTHLY       = '1200.00';

    /** class_id => date (para referencia de mes) */
    private const CLASSES = [
        1  => '2026-01-05',
        2  => '2026-01-28',
        3  => '2026-02-20',
        4  => '2026-03-16',
        5  => '2026-04-07',
        6  => '2026-04-30',
        7  => '2026-05-25', // ← única clase en mayo (pasada)
        8  => '2026-06-15', // future
        9  => '2026-07-08', // future
        10 => '2026-07-31', // future
    ];

    /**
     * Escenarios: [first_name, last_name, attendances_per_class_id]
     * attendance: 'P'=PRESENT, 'A'=ABSENT, 'L'=LATE, 'LJ'=JUSTIFIED_LATE, 'AJ'=JUSTIFIED_ABSENCE
     */
    private const SCENARIOS = [
        ['Ana',   'García',    [1=>'P',2=>'P',3=>'P',4=>'P',5=>'P',6=>'P',7=>'P',8=>'P',9=>'P',10=>'P']],
        ['Carlos','López',     [1=>'P',2=>'P',3=>'P',4=>'P',5=>'P',6=>'P',7=>'A',8=>'P',9=>'P',10=>'P']],
        ['María', 'Torres',    [1=>'L',2=>'L',3=>'P',4=>'P',5=>'P',6=>'P',7=>'P',8=>'P',9=>'P',10=>'P']],
        ['José',  'Hernández', [1=>'L',2=>'L',3=>'P',4=>'P',5=>'P',6=>'P',7=>'A',8=>'P',9=>'P',10=>'P']],
        ['Laura', 'Martínez',  [1=>'L',2=>'P',3=>'P',4=>'P',5=>'P',6=>'P',7=>'P',8=>'P',9=>'P',10=>'P']],
    ];

    public function run(): void
    {
        // Validar que las clases existen
        $existingClasses = DB::table('classes')
            ->whereIn('id', array_keys(self::CLASSES))
            ->pluck('id')
            ->toArray();

        if (count($existingClasses) < count(self::CLASSES)) {
            $this->command->error('Faltan clases. Corre ClassAttendanceSeeder primero.');
            return;
        }

        $role = DB::table('roles')->where('name', 'BASIC')->first();

        foreach (self::SCENARIOS as [$firstName, $lastName, $pattern]) {
            $email  = strtolower($firstName . '.' . $lastName . '@test.iu') ;
            $email  = str_replace(['á','é','í','ó','ú'], ['a','e','i','o','u'], $email);

            // Evitar duplicados
            $userId = DB::table('users')->where('email', $email)->value('id');

            if (!$userId) {
                $userId = DB::table('users')->insertGetId([
                    'first_name'    => $firstName,
                    'last_name'     => $lastName,
                    'email'         => $email,
                    'password'      => Hash::make('password'),
                    'campus'        => self::CAMPUS,
                    'generation_id' => self::GENERATION_ID,
                    'user_type'     => 'BEC_ACTIVE',
                    'phone'         => '9990000000',
                    'active'        => true,
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);

                if ($role) {
                    DB::table('model_has_roles')->insert([
                        'role_id'    => $role->id,
                        'model_type' => 'App\\Models\\User',
                        'model_id'   => $userId,
                    ]);
                }
            }

            // Scholarship profile
            $profileExists = DB::table('scholarship_profiles')->where('user_id', $userId)->exists();
            if (!$profileExists) {
                DB::table('scholarship_profiles')->insert([
                    'user_id'              => $userId,
                    'scholarship_type'     => 'IU',
                    'monthly_amount'       => self::MONTHLY,
                    'reticula_start_date'  => '2024-01-01',
                    'reticula_end_date'    => '2028-01-01',
                    'created_at'           => now(),
                    'updated_at'           => now(),
                ]);
            }

            // Attendances para cada clase
            foreach ($pattern as $classId => $code) {
                $exists = DB::table('attendances')
                    ->where('user_id', $userId)
                    ->where('class_id', $classId)
                    ->exists();

                if ($exists) continue;

                [$status, $checkIn, $checkOut, $latePenaltyConsumed] = $this->resolveCode($code);

                DB::table('attendances')->insert([
                    'user_id'                          => $userId,
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
            }

            $this->command->line("  ✓ {$firstName} {$lastName} (user_id={$userId}) — " . $this->describePattern($pattern));
        }

        $this->command->info('TestBecarioSeeder completado. 5 becarios listos para pruebas.');
    }

    private function resolveCode(string $code): array
    {
        return match ($code) {
            'P'  => ['PRESENT',           '09:02:00', '11:00:00', false],
            'A'  => ['ABSENT',            null,       null,       false],
            'AJ' => ['JUSTIFIED_ABSENCE', null,       null,       false],
            'L'  => ['LATE',              '09:20:00', '11:00:00', false],
            'LJ' => ['JUSTIFIED_LATE',    '09:15:00', '11:00:00', false],
            default => ['PRESENT',        '09:02:00', '11:00:00', false],
        };
    }

    private function describePattern(array $pattern): string
    {
        $counts = array_count_values($pattern);
        $parts  = [];
        if (!empty($counts['P']))  $parts[] = $counts['P']  . ' pres';
        if (!empty($counts['A']))  $parts[] = $counts['A']  . ' falta';
        if (!empty($counts['L']))  $parts[] = $counts['L']  . ' ret';
        if (!empty($counts['AJ'])) $parts[] = $counts['AJ'] . ' falta-just';
        if (!empty($counts['LJ'])) $parts[] = $counts['LJ'] . ' ret-just';
        return implode(', ', $parts);
    }
}
