<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Inserta asistencias para user_id=2, class_id 1–18.
 * Retardos en class_id 5 y 12; el resto con PRESENT.
 * Idempotente: usa upsert para no duplicar si ya existe el registro.
 */
class User2AttendanceSeeder extends Seeder
{
    private const USER_ID = 2;
    private const LATE_CLASS_IDS = [5, 12];

    public function run(): void
    {
        $rows = [];

        for ($classId = 1; $classId <= 18; $classId++) {
            $status = in_array($classId, self::LATE_CLASS_IDS) ? 'LATE' : 'PRESENT';

            $rows[] = [
                'user_id'      => self::USER_ID,
                'class_id'     => $classId,
                'status'       => $status,
                'class_status' => 'COMPLETED',
                'late_penalty_consumed' => false,
                'created_at'   => now(),
                'updated_at'   => now(),
            ];
        }

        DB::table('attendances')->upsert(
            $rows,
            ['user_id', 'class_id'],
            ['status', 'class_status', 'updated_at']
        );

        $this->command->info('User2AttendanceSeeder: 18 registros insertados/actualizados para user_id=' . self::USER_ID);
    }
}
