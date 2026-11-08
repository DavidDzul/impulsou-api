<?php

namespace Tests\Feature;

use App\Enums\BankFileConcept;
use App\Enums\RefrendStatus;
use App\Enums\RefrendType;
use App\Enums\ScholarshipType;
use App\Models\Bank;
use App\Models\ScholarshipPaymentBatch;
use App\Models\ScholarshipPaymentData;
use App\Models\ScholarshipRefrend;
use App\Models\User;
use App\Services\Scholarship\BankPaymentFileName;
use App\Services\Scholarship\BankPaymentFileSerializer;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * sdd/archivo-pago-beca-apoyo PR2 (task 2.5, 2.6): the combined bank file is
 * replaced by one file per concept at GET batches/{batch}/export/{concept}
 * (beca|apoyo), plus a per-concept exportSummary. The serializer and its
 * 108-char CRLF record are untouched.
 */
class ScholarshipPaymentBankFileConceptExportTest extends TestCase
{
    use RefreshDatabase;

    private const CAMPUS = 'MERIDA';
    private const YEAR   = 2026;
    private const MONTH  = 5;

    private User $rootAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->rootAdmin = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
        $this->rootAdmin->assignRole('ROOT_ADMINISTRATION');
    }

    private function url(int $batchId, string $tail): string
    {
        return "/api/admin/scholarship-payments/batches/{$batchId}/{$tail}";
    }

    /**
     * @param array<int, array{refrend?: array, payment?: array}> $specs
     */
    private function paidBatch(array $specs, int $month = self::MONTH): ScholarshipPaymentBatch
    {
        $batch = ScholarshipPaymentBatch::create([
            'campus'          => self::CAMPUS,
            'period_year'     => self::YEAR,
            'period_month'    => $month,
            'refrend_count'   => 999,
            'total_amount'    => '999999.99',
            'processed_by_id' => $this->rootAdmin->id,
            'processed_at'    => now(),
        ]);

        foreach ($specs as $spec) {
            $user = User::factory()->create([
                'user_type'  => 'BEC_ACTIVE',
                'campus'     => self::CAMPUS,
                'active'     => true,
                'enrollment' => 'MAT-' . random_int(100000, 999999),
            ]);
            ScholarshipPaymentData::create(array_merge([
                'user_id'        => $user->id,
                'bank_id'        => Bank::where('code', '012')->value('id'),
                'bank_name'      => 'BBVA',
                'account_number' => '0123456789',
                'curp'           => 'CURP010101HDFXXX01',
                'rfc'            => 'PEPJ800101ABC',
            ], $spec['payment'] ?? []));

            ScholarshipRefrend::create(array_merge([
                'user_id'                      => $user->id,
                'period_year'                  => self::YEAR,
                'period_month'                 => $month,
                'refrend_type'                 => RefrendType::NORMAL->value,
                'status'                       => RefrendStatus::PAID->value,
                'workflow_status'              => 'CLOSED',
                'locked_at'                    => now(),
                'locked_by_id'                 => $this->rootAdmin->id,
                'payment_batch_id'             => $batch->id,
                'base_amount'                  => 1000.00,
                'discount_percentage'          => 0,
                'discount_amount'              => 0,
                'final_amount'                 => 1000.00,
                'amount_pending_from_previous' => 0,
                'snapshot_name'                => 'Test Becario',
                'snapshot_campus'              => self::CAMPUS,
                'snapshot_scholarship_type'    => ScholarshipType::IU->value,
            ], $spec['refrend'] ?? []));
        }

        return $batch;
    }

    /** golden 1545.45 / 154.55 + 1000.00 / 0 + floored 0.01 / 0 */
    private function mixedBatch(): ScholarshipPaymentBatch
    {
        return $this->paidBatch([
            ['refrend' => [
                'snapshot_name' => 'A Golden', 'base_amount' => 2000.00, 'snapshot_monto_apoyo' => 200.00,
                'snapshot_gross_amount' => 2200.00, 'discount_amount' => 500.00, 'final_amount' => 1700.00,
            ]],
            ['refrend' => ['snapshot_name' => 'B Sin Apoyo', 'final_amount' => 1000.00]],
            ['refrend' => [
                'snapshot_name' => 'C Piso', 'base_amount' => 2000.00, 'snapshot_monto_apoyo' => 200.00,
                'snapshot_gross_amount' => 2200.00, 'discount_amount' => 2200.00, 'final_amount' => 0.00,
            ]],
        ]);
    }

    /** @return array<int, string> */
    private function lines(string $body): array
    {
        return array_values(array_filter(explode("\r\n", $body)));
    }

    private function centavos(string $line): int
    {
        return (int) substr($line, 47, 15);
    }

    private function download(ScholarshipPaymentBatch $batch, string $concept)
    {
        return $this->actingAs($this->rootAdmin)->get($this->url($batch->id, "export/{$concept}"));
    }

    /** @test */
    public function beca_file_carries_beca_amounts_and_apoyo_file_only_the_non_zero_apoyo(): void
    {
        $batch = $this->mixedBatch();

        $beca  = $this->lines($this->download($batch, 'beca')->streamedContent());
        $apoyo = $this->lines($this->download($batch, 'apoyo')->streamedContent());

        $this->assertSame([154545, 100000, 1], array_map(fn ($l) => $this->centavos($l), $beca));
        $this->assertSame([15455], array_map(fn ($l) => $this->centavos($l), $apoyo), 'zero-apoyo rows are omitted');
    }

    /** @test */
    public function the_two_files_add_up_to_the_combined_total_of_the_summary(): void
    {
        $batch = $this->mixedBatch();

        $sum = fn (string $concept) => array_sum(array_map(
            fn ($l) => $this->centavos($l),
            $this->lines($this->download($batch, $concept)->streamedContent())
        ));
        $summary = $this->actingAs($this->rootAdmin)->getJson($this->url($batch->id, 'export/summary'));

        $this->assertSame('2700.01', $summary->json('data.total_amount'));
        $this->assertSame(270001, $sum('beca') + $sum('apoyo'));
    }

    /** @test */
    public function each_file_is_byte_identical_to_the_serializer_over_its_concept_rows_and_restarts_the_consecutivo(): void
    {
        $batch      = $this->mixedBatch();
        $serializer = new BankPaymentFileSerializer();
        $base       = ['rfc' => 'PEPJ800101ABC', 'account_number' => '0123456789'];

        $expectedApoyo = $serializer->serialize([
            $base + ['snapshot_name' => 'A Golden', 'total_to_pay' => '154.55'],
        ]);
        $expectedBeca = $serializer->serialize([
            $base + ['snapshot_name' => 'A Golden', 'total_to_pay' => '1545.45'],
            $base + ['snapshot_name' => 'B Sin Apoyo', 'total_to_pay' => '1000.00'],
            $base + ['snapshot_name' => 'C Piso', 'total_to_pay' => '0.01'],
        ]);

        $apoyoResponse = $this->download($batch, 'apoyo');
        $becaResponse  = $this->download($batch, 'beca');

        $this->assertSame($expectedApoyo, $apoyoResponse->streamedContent());
        $this->assertSame($expectedBeca, $becaResponse->streamedContent());
        $this->assertStringStartsWith('000000001', $apoyoResponse->streamedContent());
        $apoyoResponse->assertHeader(
            'Content-Disposition',
            'attachment; filename=' . BankPaymentFileName::forBatch($batch->fresh(), BankFileConcept::APOYO)
        );
        $this->assertStringEndsWith('_APOYO.TXT', BankPaymentFileName::forBatch($batch->fresh(), BankFileConcept::APOYO));
    }

    /** @test */
    public function a_bank_error_blocks_both_concepts_with_the_same_body_and_lists_each_becario_once(): void
    {
        $batch = $this->paidBatch([
            ['refrend' => [
                'snapshot_name' => 'Con Apoyo', 'base_amount' => 2000.00, 'snapshot_monto_apoyo' => 200.00,
                'snapshot_gross_amount' => 2200.00, 'discount_amount' => 500.00, 'final_amount' => 1700.00,
            ], 'payment' => ['account_number' => 'ABC123']],
        ]);

        $beca  = $this->actingAs($this->rootAdmin)->getJson($this->url($batch->id, 'export/beca'));
        $apoyo = $this->actingAs($this->rootAdmin)->getJson($this->url($batch->id, 'export/apoyo'));
        $sum   = $this->actingAs($this->rootAdmin)->getJson($this->url($batch->id, 'export/summary'));

        foreach ([$beca, $apoyo, $sum] as $response) {
            $response->assertStatus(422);
            $this->assertCount(1, $response->json('data.invalid_rows'));
            $this->assertSame('Con Apoyo', $response->json('data.invalid_rows.0.snapshot_name'));
        }
        $this->assertSame($beca->json(), $apoyo->json());
    }

    /** @test */
    public function a_bank_error_on_a_zero_apoyo_row_still_blocks_the_apoyo_file(): void
    {
        $batch = $this->paidBatch([
            ['refrend' => [
                'snapshot_name' => 'Con Apoyo', 'base_amount' => 2000.00, 'snapshot_monto_apoyo' => 200.00,
                'snapshot_gross_amount' => 2200.00, 'discount_amount' => 500.00, 'final_amount' => 1700.00,
            ]],
            ['refrend' => ['snapshot_name' => 'Sin Apoyo Malo', 'final_amount' => 1000.00], 'payment' => ['account_number' => 'ABC123']],
        ]);

        $response = $this->actingAs($this->rootAdmin)->getJson($this->url($batch->id, 'export/apoyo'));

        $response->assertStatus(422);
        $this->assertSame('Sin Apoyo Malo', $response->json('data.invalid_rows.0.snapshot_name'));
    }

    /** @test */
    public function a_concept_without_amounts_returns_422_while_the_other_still_downloads(): void
    {
        $batch = $this->paidBatch([['refrend' => ['snapshot_name' => 'Solo Beca', 'final_amount' => 1000.00]]]);

        $apoyo = $this->actingAs($this->rootAdmin)->getJson($this->url($batch->id, 'export/apoyo'));

        $apoyo->assertStatus(422);
        $this->assertFalse($apoyo->json('res'));
        $this->assertSame('El lote no tiene montos de apoyo a dispersar.', $apoyo->json('msg'));
        $this->download($batch, 'beca')->assertStatus(200);

        $onlyApoyo = $this->paidBatch([
            ['refrend' => [
                'snapshot_name' => 'X', 'base_amount' => 200.00, 'snapshot_monto_apoyo' => 200.00,
                'snapshot_gross_amount' => 200.00, 'discount_amount' => 0.00, 'final_amount' => 200.00,
            ]],
        ], 6);
        $this->assertSame(
            'El lote no tiene montos de beca a dispersar.',
            $this->actingAs($this->rootAdmin)->getJson($this->url($onlyApoyo->id, 'export/beca'))->json('msg')
        );
    }

    /** @test */
    public function the_combined_export_route_is_gone_and_unknown_concepts_are_404(): void
    {
        $batch = $this->mixedBatch();

        $this->actingAs($this->rootAdmin)->getJson($this->url($batch->id, 'export'))->assertStatus(404);
        $this->actingAs($this->rootAdmin)->getJson($this->url($batch->id, 'export/combined'))->assertStatus(404);
        $this->actingAs($this->rootAdmin)->getJson($this->url($batch->id, 'export/BECA'))->assertStatus(404);
    }

    /** @test */
    public function the_concept_routes_keep_the_export_permission(): void
    {
        $batch  = $this->mixedBatch();
        $reader = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
        $reader->givePermissionTo('ADM_READ_PAYMENTS', 'ADM_PROCESS_PAYMENTS');
        $nobody = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);

        foreach (['beca', 'apoyo'] as $concept) {
            $this->actingAs($reader)->getJson($this->url($batch->id, "export/{$concept}"))->assertStatus(403);
            $this->actingAs($nobody)->getJson($this->url($batch->id, "export/{$concept}"))->assertStatus(403);
        }
    }

    /** @test */
    public function an_unknown_batch_is_rejected_like_before(): void
    {
        $this->actingAs($this->rootAdmin)->getJson($this->url(999999, 'export/apoyo'))->assertStatus(400);
    }

    /** @test */
    public function a_batch_without_paid_rows_is_422_for_both_concepts(): void
    {
        $batch = $this->paidBatch([]);

        foreach (['beca', 'apoyo'] as $concept) {
            $response = $this->actingAs($this->rootAdmin)->getJson($this->url($batch->id, "export/{$concept}"));
            $response->assertStatus(422);
            $this->assertSame('El lote no tiene becarios con monto a dispersar.', $response->json('msg'));
        }
    }

    /** @test */
    public function the_summary_reports_each_file_and_keeps_the_combined_total(): void
    {
        $batch = $this->mixedBatch();

        $response = $this->actingAs($this->rootAdmin)->getJson($this->url($batch->id, 'export/summary'));

        $response->assertStatus(200);
        $this->assertSame(3, $response->json('data.count'));
        $this->assertSame('2700.01', $response->json('data.total_amount'));
        $this->assertSame('NORMAL', $response->json('data.batch_type'));
        $this->assertArrayNotHasKey('filename', $response->json('data'));
        $this->assertSame(3, $response->json('data.files.beca.count'));
        $this->assertSame('2545.46', $response->json('data.files.beca.total_amount'));
        $this->assertSame(1, $response->json('data.files.apoyo.count'));
        $this->assertSame('154.55', $response->json('data.files.apoyo.total_amount'));
        $this->assertSame(
            BankPaymentFileName::forBatch($batch->fresh(), BankFileConcept::BECA),
            $response->json('data.files.beca.filename')
        );
        $this->assertSame(
            BankPaymentFileName::forBatch($batch->fresh(), BankFileConcept::APOYO),
            $response->json('data.files.apoyo.filename')
        );
    }

    /** @test */
    public function the_summary_of_a_batch_without_apoyo_reports_an_empty_apoyo_file(): void
    {
        $batch = $this->paidBatch([['refrend' => ['snapshot_name' => 'Solo Beca', 'final_amount' => 1000.00]]]);

        $response = $this->actingAs($this->rootAdmin)->getJson($this->url($batch->id, 'export/summary'));

        $response->assertStatus(200);
        $this->assertSame(0, $response->json('data.files.apoyo.count'));
        $this->assertSame('0.00', $response->json('data.files.apoyo.total_amount'));
        $this->assertSame(1, $response->json('data.files.beca.count'));
        $this->assertSame('1000.00', $response->json('data.total_amount'));
    }
}
