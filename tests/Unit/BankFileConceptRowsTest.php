<?php

namespace Tests\Unit;

use App\Enums\BankFileConcept;
use App\Services\Scholarship\BankPaymentFileSerializer;
use App\Support\Scholarship\BankFileConceptRows;
use PHPUnit\Framework\TestCase;

/**
 * sdd/archivo-pago-beca-apoyo (PR2, task 2.2): pure mapper from paidRows()
 * output to the rows of ONE concept file.
 */
class BankFileConceptRowsTest extends TestCase
{
    private function row(string $name, string $total, string $beca, string $apoyo): array
    {
        return [
            'refrend_id'     => 1,
            'user_id'        => 1,
            'snapshot_name'  => $name,
            'rfc'            => 'AAAA800101AB1',
            'account_number' => '0123456789',
            'bank_code'      => '012',
            'total_to_pay'   => $total,
            'beca_amount'    => $beca,
            'apoyo_amount'   => $apoyo,
        ];
    }

    /** @test */
    public function maps_the_concept_amount_into_total_to_pay(): void
    {
        $rows = [$this->row('A', '2200.00', '2000.00', '200.00')];

        $beca  = BankFileConceptRows::for($rows, BankFileConcept::BECA);
        $apoyo = BankFileConceptRows::for($rows, BankFileConcept::APOYO);

        $this->assertSame('2000.00', $beca[0]['total_to_pay']);
        $this->assertSame('200.00', $apoyo[0]['total_to_pay']);
        $this->assertSame('A', $beca[0]['snapshot_name']);
        $this->assertSame('0123456789', $apoyo[0]['account_number']);
    }

    /** @test */
    public function drops_zero_rows_and_preserves_order(): void
    {
        $rows = [
            $this->row('A', '100.00', '100.00', '0.00'),
            $this->row('B', '50.00', '0.00', '50.00'),
            $this->row('C', '70.00', '60.00', '10.00'),
        ];

        $beca  = BankFileConceptRows::for($rows, BankFileConcept::BECA);
        $apoyo = BankFileConceptRows::for($rows, BankFileConcept::APOYO);

        $this->assertSame(['A', 'C'], array_column($beca, 'snapshot_name'));
        $this->assertSame(['B', 'C'], array_column($apoyo, 'snapshot_name'));
        $this->assertSame([0, 1], array_keys($beca));
    }

    /** @test */
    public function empty_when_the_concept_has_no_amounts(): void
    {
        $rows = [$this->row('A', '100.00', '100.00', '0.00')];

        $this->assertSame([], BankFileConceptRows::for($rows, BankFileConcept::APOYO));
    }

    /** @test */
    public function consecutivo_restarts_at_one_in_each_file(): void
    {
        $rows = [
            $this->row('A', '100.00', '100.00', '0.00'),
            $this->row('B', '50.00', '40.00', '10.00'),
        ];
        $serializer = new BankPaymentFileSerializer();

        $beca  = explode("\r\n", rtrim($serializer->serialize(BankFileConceptRows::for($rows, BankFileConcept::BECA)), "\r\n"));
        $apoyo = explode("\r\n", rtrim($serializer->serialize(BankFileConceptRows::for($rows, BankFileConcept::APOYO)), "\r\n"));

        $this->assertCount(2, $beca);
        $this->assertCount(1, $apoyo);
        $this->assertStringStartsWith('000000001', $apoyo[0]);
        $this->assertStringStartsWith('000000001', $beca[0]);
        $this->assertStringStartsWith('000000002', $beca[1]);
    }

    /** @test */
    public function both_files_add_up_to_the_combined_total(): void
    {
        $rows = [
            $this->row('A', '2200.00', '2000.00', '200.00'),
            $this->row('B', '0.01', '0.01', '0.00'),
            $this->row('C', '545.45', '454.55', '90.90'),
        ];
        $sum = fn (array $r) => array_sum(array_map(fn ($x) => (int) round((float) $x['total_to_pay'] * 100), $r));

        $combined = array_sum(array_map(fn ($x) => (int) round((float) $x['total_to_pay'] * 100), $rows));

        $this->assertSame(
            $combined,
            $sum(BankFileConceptRows::for($rows, BankFileConcept::BECA)) + $sum(BankFileConceptRows::for($rows, BankFileConcept::APOYO))
        );
    }
}
