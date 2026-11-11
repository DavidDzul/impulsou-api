<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * sdd/bank-file-split-other-banks-cheques PR4: the six legacy per-refrend
 * manual-payment columns are dropped; the child table is the only source.
 */
class DropLegacyManualPaymentColumnsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const LEGACY = [
        'cheque_number',
        'cheque_delivered_at',
        'cheque_notes',
        'transfer_reference',
        'transfer_paid_at',
        'transfer_notes',
    ];

    private const PATH = __DIR__ . '/../../database/migrations/2026_11_11_100000_drop_legacy_manual_payment_columns_from_scholarship_refrends.php';

    private function migration(): \Illuminate\Database\Migrations\Migration
    {
        require_once self::PATH;

        return new \DropLegacyManualPaymentColumnsFromScholarshipRefrends();
    }

    /** @test */
    public function up_drops_the_six_legacy_columns_and_keeps_paid_payment_method(): void
    {
        foreach (self::LEGACY as $col) {
            $this->assertFalse(Schema::hasColumn('scholarship_refrends', $col), $col);
        }
        $this->assertTrue(Schema::hasColumn('scholarship_refrends', 'paid_payment_method'));
    }

    /** @test */
    public function down_re_adds_the_six_columns_as_nullable_and_up_drops_them_again(): void
    {
        $migration = $this->migration();

        $migration->down();
        $this->assertTrue(Schema::hasColumns('scholarship_refrends', self::LEGACY));
        $this->assertTrue(Schema::hasColumn('scholarship_refrends', 'paid_payment_method'));

        $migration->up();
        foreach (self::LEGACY as $col) {
            $this->assertFalse(Schema::hasColumn('scholarship_refrends', $col), $col);
        }
        $this->assertTrue(Schema::hasColumn('scholarship_refrends', 'paid_payment_method'));
    }
}
