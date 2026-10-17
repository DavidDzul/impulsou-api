<?php

namespace Tests\Unit;

use App\Models\ScholarshipRefrend;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * sdd/limpieza-situaciones-en-desuso (api-3, amendment #2019): guard-only
 * migration. It never deletes or rewrites data; it refuses to run when a
 * dead situation row still exists and otherwise drops the 3 dead columns.
 */
class DropDeadRefrendSituationsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = __DIR__ . '/../../database/migrations/2026_10_16_100000_drop_dead_refrend_situations.php';
    private const DEAD_COLUMNS = ['suspension_percentage', 'suspension_scope', 'refund_amount_from_previous'];

    private int $sequence = 0;

    private function migration(): Migration
    {
        return require self::MIGRATION_PATH;
    }

    /** RefreshDatabase already ran up(); restore the columns so up() starts from "present". */
    private function restoreColumns(): void
    {
        $this->migration()->down();
        foreach (self::DEAD_COLUMNS as $column) {
            $this->assertTrue(Schema::hasColumn('scholarship_refrends', $column));
        }
    }

    private function makeRefrend(array $deadValues = []): ScholarshipRefrend
    {
        $user = User::factory()->create();
        $refrend = ScholarshipRefrend::create([
            'user_id'       => $user->id,
            'period_year'   => 2030,
            'period_month'  => ++$this->sequence,
            'refrend_type'  => 'NORMAL',
            'base_amount'   => 2000,
            'final_amount'  => 2000,
            'snapshot_name' => $user->name ?? 'Test User',
        ]);

        if ($deadValues !== []) {
            DB::table('scholarship_refrends')->where('id', $refrend->id)->update($deadValues);
        }

        return $refrend;
    }

    private function assertColumnsStillPresent(): void
    {
        foreach (self::DEAD_COLUMNS as $column) {
            $this->assertTrue(Schema::hasColumn('scholarship_refrends', $column), "{$column} must remain");
        }
    }

    private function assertGuardTrips(ScholarshipRefrend $offender, ScholarshipRefrend $clean): void
    {
        try {
            $this->migration()->up();
            $this->fail('Expected RuntimeException from the guard.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString((string) $offender->id, $e->getMessage());
        }

        $this->assertColumnsStillPresent();
        $this->assertSame(
            1,
            DB::table('scholarship_refrends')->where('id', $offender->id)->count(),
            'nothing may be deleted'
        );
        $this->assertNotNull($clean->fresh());
    }

    /** @test */
    public function guard_throws_naming_the_refrend_for_each_dead_resolution_type(): void
    {
        foreach (['SUSPENDIDA', 'REEMBOLSO_PARCIAL'] as $dead) {
            $this->restoreColumns();
            $offender = $this->makeRefrend(['resolution_type' => $dead]);
            $clean = $this->makeRefrend();

            $this->assertGuardTrips($offender, $clean);
            $this->assertSame($dead, DB::table('scholarship_refrends')->where('id', $offender->id)->value('resolution_type'));

            DB::table('scholarship_refrends')->whereIn('id', [$offender->id, $clean->id])->delete();
        }
    }

    /** @test */
    public function guard_throws_for_pendiente_notificacion_workflow_status(): void
    {
        $this->restoreColumns();
        $offender = $this->makeRefrend(['workflow_status' => 'PENDIENTE_NOTIFICACION']);
        $clean = $this->makeRefrend();

        $this->assertGuardTrips($offender, $clean);
    }

    /** @test */
    public function guard_throws_for_non_zero_refund_amount_from_previous(): void
    {
        $this->restoreColumns();
        $offender = $this->makeRefrend(['refund_amount_from_previous' => 150.00]);
        $clean = $this->makeRefrend();

        $this->assertGuardTrips($offender, $clean);
        $this->assertEquals(150.00, DB::table('scholarship_refrends')->where('id', $offender->id)->value('refund_amount_from_previous'));
    }

    /** @test */
    public function guard_throws_naming_the_refrend_of_a_dead_advance_month(): void
    {
        $this->restoreColumns();
        $origin = $this->makeRefrend();
        $target = $this->makeRefrend();
        $headerId = DB::table('scholarship_advance_payments')->insertGetId([
            'user_id'             => $origin->user_id,
            'origin_refrend_id'   => $origin->id,
            'origin_period_year'  => 2030,
            'origin_period_month' => 1,
            'months_count'        => 1,
            'total_amount'        => 2000.00,
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);
        DB::table('scholarship_advance_payment_months')->insert([
            'advance_payment_id'      => $headerId,
            'user_id'                 => $target->user_id,
            'period_year'             => 2030,
            'period_month'            => 2,
            'amount'                  => 2000.00,
            'refrend_id'              => $target->id,
            'settled_resolution_type' => 'SUSPENDIDA',
            'created_at'              => now(),
            'updated_at'              => now(),
        ]);

        $this->assertGuardTrips($target, $origin);
        $this->assertSame(1, DB::table('scholarship_advance_payment_months')->count());
    }

    /** @test */
    public function clean_database_drops_the_three_columns(): void
    {
        $this->restoreColumns();
        $live = $this->makeRefrend();

        $this->migration()->up();

        foreach (self::DEAD_COLUMNS as $column) {
            $this->assertFalse(Schema::hasColumn('scholarship_refrends', $column));
        }
        $this->assertNotNull($live->fresh());
    }

    /** @test */
    public function up_is_idempotent_when_the_columns_are_already_gone(): void
    {
        $this->migration()->up();
        $this->migration()->up();

        foreach (self::DEAD_COLUMNS as $column) {
            $this->assertFalse(Schema::hasColumn('scholarship_refrends', $column));
        }
    }

    /** @test */
    public function down_re_adds_the_columns_nullable_with_zero_default_for_refund(): void
    {
        $this->migration()->up();

        $this->migration()->down();
        $this->migration()->down();

        $this->assertColumnsStillPresent();
        $refrend = $this->makeRefrend();
        $row = DB::table('scholarship_refrends')->where('id', $refrend->id)->first();
        $this->assertNull($row->suspension_percentage);
        $this->assertNull($row->suspension_scope);
        $this->assertEquals(0, $row->refund_amount_from_previous);
    }
}
