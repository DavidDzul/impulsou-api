<?php

namespace Tests\Feature;

use App\Enums\RefrendStatus;
use App\Enums\RefrendType;
use App\Models\ScholarshipRefrend;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * sdd/bank-file-split-other-banks-cheques PR1: scholarship_refrend_manual_payments.
 */
class ManualPaymentsTableMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function refrend(int $month = 5): ScholarshipRefrend
    {
        $user = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'campus' => 'MERIDA', 'active' => true]);

        return ScholarshipRefrend::create([
            'user_id'         => $user->id,
            'period_year'     => 2026,
            'period_month'    => $month,
            'refrend_type'    => RefrendType::NORMAL->value,
            'status'          => RefrendStatus::DRAFT->value,
            'workflow_status' => 'CLOSED',
            'base_amount'     => 1000,
            'discount_amount' => 0,
            'final_amount'    => 1000,
            'snapshot_name'   => 'Becario ' . $user->id,
        ]);
    }

    private function row(int $refrendId, string $concept, string $method, string $reference): array
    {
        return [
            'refrend_id'           => $refrendId,
            'concept'              => $concept,
            'method'               => $method,
            'reference'            => $reference,
            'reference_normalized' => strtolower(trim($reference)),
            'amount'               => '800.00',
            'paid_at'              => '2026-05-20',
            'created_at'           => now(),
            'updated_at'           => now(),
        ];
    }

    public function test_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('scholarship_refrend_manual_payments'));
        $this->assertTrue(Schema::hasColumns('scholarship_refrend_manual_payments', [
            'id', 'refrend_id', 'concept', 'method', 'reference', 'reference_normalized',
            'amount', 'paid_at', 'notes', 'created_by_id', 'created_at', 'updated_at',
        ]));
    }

    public function test_refrend_and_concept_are_unique(): void
    {
        $r = $this->refrend();
        DB::table('scholarship_refrend_manual_payments')->insert($this->row($r->id, 'beca', 'CHEQUE', 'A-1'));

        $this->expectException(QueryException::class);
        DB::table('scholarship_refrend_manual_payments')->insert($this->row($r->id, 'beca', 'CHEQUE', 'A-2'));
    }

    public function test_method_and_normalized_reference_are_unique_across_refrends(): void
    {
        $a = $this->refrend(5);
        $b = $this->refrend(6);
        DB::table('scholarship_refrend_manual_payments')->insert($this->row($a->id, 'beca', 'CHEQUE', 'AB-1'));

        $this->expectException(QueryException::class);
        DB::table('scholarship_refrend_manual_payments')->insert($this->row($b->id, 'beca', 'CHEQUE', ' ab-1 '));
    }

    public function test_same_reference_is_allowed_for_a_different_method(): void
    {
        $a = $this->refrend(5);
        $b = $this->refrend(6);
        DB::table('scholarship_refrend_manual_payments')->insert($this->row($a->id, 'beca', 'CHEQUE', 'AB-1'));
        DB::table('scholarship_refrend_manual_payments')->insert($this->row($b->id, 'beca', 'MANUAL_TRANSFER', 'AB-1'));

        $this->assertSame(2, DB::table('scholarship_refrend_manual_payments')->count());
    }

    public function test_rows_cascade_when_the_refrend_is_deleted(): void
    {
        $r = $this->refrend();
        DB::table('scholarship_refrend_manual_payments')->insert($this->row($r->id, 'beca', 'CHEQUE', 'A-1'));

        DB::table('scholarship_refrends')->where('id', $r->id)->delete();

        $this->assertSame(0, DB::table('scholarship_refrend_manual_payments')->count());
    }
}
