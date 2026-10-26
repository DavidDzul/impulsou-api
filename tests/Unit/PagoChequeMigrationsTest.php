<?php

namespace Tests\Unit;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * sdd/pago-cheque, tasks 1.2: migration A (scholarship_payment_data) and
 * migration B (scholarship_refrends cheque snapshot columns), up and down.
 */
class PagoChequeMigrationsTest extends TestCase
{
    use RefreshDatabase;

    private const PATH_A = __DIR__ . '/../../database/migrations/2026_10_25_100000_add_payment_method_to_scholarship_payment_data.php';
    private const PATH_B = __DIR__ . '/../../database/migrations/2026_10_25_100100_add_cheque_columns_to_scholarship_refrends.php';

    private function migrationA(): \Illuminate\Database\Migrations\Migration
    {
        require_once self::PATH_A;

        return new \AddPaymentMethodToScholarshipPaymentData();
    }

    private function migrationB(): \Illuminate\Database\Migrations\Migration
    {
        require_once self::PATH_B;

        return new \AddChequeColumnsToScholarshipRefrends();
    }

    private function insertSpd(array $overrides = []): int
    {
        $user = User::factory()->create();

        return DB::table('scholarship_payment_data')->insertGetId(array_merge([
            'user_id'    => $user->id,
            'curp'       => 'ABCD010101HDFRRL09',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    /** @test */
    public function migration_a_adds_columns_with_transfer_default_and_nullable_bank_fields(): void
    {
        $this->assertTrue(Schema::hasColumns('scholarship_payment_data', ['payment_method', 'cheque_beneficiary']));

        $id = $this->insertSpd();
        $this->assertSame('TRANSFER', DB::table('scholarship_payment_data')->where('id', $id)->value('payment_method'));

        // bank fields accept NULL (CHEQUE rows carry no bank data)
        $id2 = $this->insertSpd(['payment_method' => 'CHEQUE', 'bank_name' => null, 'account_number' => null]);
        $this->assertNull(DB::table('scholarship_payment_data')->where('id', $id2)->value('bank_name'));
    }

    /** @test */
    public function migration_a_down_deletes_cheque_and_bankless_rows_and_drops_columns(): void
    {
        $keep = $this->insertSpd(['bank_name' => 'BBVA', 'account_number' => '012345678901234567']);
        $this->insertSpd(['payment_method' => 'CHEQUE']);
        $this->insertSpd(['bank_name' => null, 'account_number' => null]);

        $this->migrationA()->down();

        $this->assertFalse(Schema::hasColumn('scholarship_payment_data', 'payment_method'));
        $this->assertFalse(Schema::hasColumn('scholarship_payment_data', 'cheque_beneficiary'));
        $this->assertSame([$keep], DB::table('scholarship_payment_data')->pluck('id')->all());
    }

    /** @test */
    public function migration_a_down_restores_not_null_on_bank_fields(): void
    {
        $this->migrationA()->down();

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->insertSpd(['bank_name' => null, 'account_number' => null]);
    }

    /** @test */
    public function migration_b_adds_nullable_cheque_columns_and_down_drops_them(): void
    {
        $cols = ['paid_payment_method', 'cheque_number', 'cheque_delivered_at', 'cheque_notes'];
        $this->assertTrue(Schema::hasColumns('scholarship_refrends', $cols));

        $this->migrationB()->down();

        foreach ($cols as $col) {
            $this->assertFalse(Schema::hasColumn('scholarship_refrends', $col), $col);
        }
    }
}
