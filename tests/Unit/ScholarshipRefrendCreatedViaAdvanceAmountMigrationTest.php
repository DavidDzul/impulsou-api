<?php

namespace Tests\Unit;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Covers design D2 (`created_via`) + D6 (`advance_payment_amount`) — tasks
 * 2.3 (Work Unit 2 / PR2). Purely additive columns on scholarship_refrends;
 * both existing rows and rows inserted without specifying these columns must
 * get the documented defaults so backfill is unnecessary (see design's
 * "Migration / Rollout: purely additive, no backfill").
 */
class ScholarshipRefrendCreatedViaAdvanceAmountMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = __DIR__ . '/../../database/migrations/2026_09_24_000003_add_created_via_and_advance_amount_to_scholarship_refrends_table.php';

    private function minimalRefrendRow(int $userId): array
    {
        return [
            'user_id'       => $userId,
            'period_year'   => 2026,
            'period_month'  => 9,
            'base_amount'   => 2000,
            'final_amount'  => 2000,
            'snapshot_name' => 'Test User',
            'created_at'    => now(),
            'updated_at'    => now(),
        ];
    }

    /** @test */
    public function migration_adds_both_columns_with_the_documented_defaults(): void
    {
        $this->assertTrue(Schema::hasColumn('scholarship_refrends', 'created_via'));
        $this->assertTrue(Schema::hasColumn('scholarship_refrends', 'advance_payment_amount'));

        $user = User::factory()->create();
        $id   = DB::table('scholarship_refrends')->insertGetId($this->minimalRefrendRow($user->id));

        $row = DB::table('scholarship_refrends')->find($id);

        $this->assertSame('GENERATION', $row->created_via);
        $this->assertEquals(0, $row->advance_payment_amount);
    }

    /** @test */
    public function created_via_can_be_set_to_advance_payment(): void
    {
        $user = User::factory()->create();
        $id   = DB::table('scholarship_refrends')->insertGetId(array_merge(
            $this->minimalRefrendRow($user->id),
            ['created_via' => 'ADVANCE_PAYMENT', 'advance_payment_amount' => 1500.00]
        ));

        $row = DB::table('scholarship_refrends')->find($id);

        $this->assertSame('ADVANCE_PAYMENT', $row->created_via);
        $this->assertEquals(1500.00, $row->advance_payment_amount);
    }

    /** @test */
    public function rollback_drops_both_columns_cleanly(): void
    {
        (require self::MIGRATION_PATH)->down();

        $this->assertFalse(Schema::hasColumn('scholarship_refrends', 'created_via'));
        $this->assertFalse(Schema::hasColumn('scholarship_refrends', 'advance_payment_amount'));
    }
}
