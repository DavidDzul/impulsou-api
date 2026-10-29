<?php

namespace Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * sdd/revertir-pago-adelantado S4a: cancellation metadata on the advance
 * header (design A1). Purely additive; down() must restore the old shape.
 */
class ScholarshipAdvancePaymentCancellationMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = __DIR__ . '/../../database/migrations/2026_10_29_000001_add_cancellation_fields_to_scholarship_advance_payments_table.php';

    private const NEW_COLUMNS = [
        'cancelled_at',
        'cancelled_by_id',
        'cancel_reason',
        'cancelled_cut_refrend_id',
        'cancelled_amount',
        'overpaid_amount',
    ];

    /** @test */
    public function up_adds_the_cancellation_columns(): void
    {
        foreach (self::NEW_COLUMNS as $column) {
            $this->assertTrue(
                Schema::hasColumn('scholarship_advance_payments', $column),
                "Missing column {$column}"
            );
        }
    }

    /** @test */
    public function down_drops_the_columns_and_up_restores_them(): void
    {
        $migration = require self::MIGRATION_PATH;

        $migration->down();

        foreach (self::NEW_COLUMNS as $column) {
            $this->assertFalse(Schema::hasColumn('scholarship_advance_payments', $column), "{$column} should be dropped");
        }
        $this->assertTrue(Schema::hasColumn('scholarship_advance_payments', 'status'));
        $this->assertTrue(Schema::hasColumn('scholarship_advance_payments', 'total_amount'));

        $migration->up();

        foreach (self::NEW_COLUMNS as $column) {
            $this->assertTrue(Schema::hasColumn('scholarship_advance_payments', $column), "{$column} should be back");
        }
    }

    /** @test */
    public function amounts_default_to_zero_and_the_rest_is_nullable(): void
    {
        $user    = \App\Models\User::factory()->create();
        $refrend = \App\Models\ScholarshipRefrend::create([
            'user_id' => $user->id, 'period_year' => 2026, 'period_month' => 9,
            'base_amount' => 2000, 'final_amount' => 2000, 'snapshot_name' => 'X',
        ]);

        $id = \Illuminate\Support\Facades\DB::table('scholarship_advance_payments')->insertGetId([
            'user_id' => $user->id, 'origin_refrend_id' => $refrend->id,
            'origin_period_year' => 2026, 'origin_period_month' => 9,
            'months_count' => 1, 'total_amount' => 1000,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $row = \Illuminate\Support\Facades\DB::table('scholarship_advance_payments')->find($id);

        $this->assertSame('ACTIVE', $row->status);
        $this->assertNull($row->cancelled_at);
        $this->assertNull($row->cancelled_by_id);
        $this->assertNull($row->cancel_reason);
        $this->assertNull($row->cancelled_cut_refrend_id);
        $this->assertEquals(0, $row->cancelled_amount);
        $this->assertEquals(0, $row->overpaid_amount);
    }

    /** @test */
    public function status_column_fits_partially_cancelled(): void
    {
        $this->assertGreaterThanOrEqual(
            strlen('PARTIALLY_CANCELLED'),
            20,
            'status is string(20) on header and months'
        );
    }
}
