<?php

namespace Tests\Unit\Services\Scholarship;

use App\Services\Scholarship\BankPaymentFileSerializer;
use Tests\TestCase;

/**
 * Unit tests for BankPaymentFileSerializer — pure, DB-free, turns payable
 * rows into the exact 108-char + CRLF BBVA fixed-width record (design
 * Interfaces/Contracts table, sdd/becario-payment-bank-file-export/design).
 *
 * The golden-fixture tests below are the regression gate for the whole
 * feature: they assert byte-for-byte identity against the two real sample
 * files committed at tests/Fixtures/bank/. Any drift in field widths,
 * padding, ordering, or the CRLF line ending MUST fail these tests loudly.
 *
 * Row shape matches PaymentBatchService::paidRows() (PR3, not yet wired):
 *   ['account_number' => ?string, 'rfc' => ?string, 'total_to_pay' => string, 'snapshot_name' => string]
 *
 * This serializer assumes pre-validated input. BankDataValidator (PR1) is
 * the single gate for account/RFC/amount correctness — enforced by both
 * PaymentReadinessEvaluator (payment-time) and the export controller
 * (export-time, PR3/PR4) BEFORE rows ever reach this class. The serializer
 * does not re-validate; it only formats. See
 * eleven_digit_account_number_is_not_truncated_serializer_trusts_input()
 * below for the documented boundary behavior.
 */
class BankPaymentFileSerializerTest extends TestCase
{
    private BankPaymentFileSerializer $serializer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->serializer = new BankPaymentFileSerializer();
    }

    private function fixturePath(string $filename): string
    {
        return __DIR__ . '/../../../Fixtures/bank/' . $filename;
    }

    // ── golden fixtures (the regression gate) ───────────────────────────

    /** @test */
    public function serializes_byte_identical_to_real_fixture_one(): void
    {
        $fixture = $this->fixturePath('1IUBMER 0826.TXT');

        $rows = [
            ['account_number' => '1558515343', 'rfc' => null, 'total_to_pay' => '1400.00', 'snapshot_name' => 'ALAN JESUS DZUL MAY'],
            ['account_number' => '1558515351', 'rfc' => null, 'total_to_pay' => '1200.00', 'snapshot_name' => 'YESENIA GUADALUPE ORTIZ POOL'],
            ['account_number' => '1558515386', 'rfc' => null, 'total_to_pay' => '0.01', 'snapshot_name' => 'JOSE MIGUEL ANGEL CAB PUCH'],
            ['account_number' => '1558515453', 'rfc' => null, 'total_to_pay' => '0.01', 'snapshot_name' => 'SOFIA GUADALUPE UICAB PECH'],
        ];

        $output = $this->serializer->serialize($rows);

        $this->assertSame(file_get_contents($fixture), $output);
    }

    /** @test */
    public function serializes_byte_identical_to_real_fixture_two(): void
    {
        $fixture = $this->fixturePath('2IUAMER 0826.TXT');

        $rows = [
            ['account_number' => '1558515343', 'rfc' => null, 'total_to_pay' => '400.00', 'snapshot_name' => 'ALAN JESUS DZUL MAY'],
            ['account_number' => '1558515351', 'rfc' => null, 'total_to_pay' => '400.00', 'snapshot_name' => 'YESENIA GUADALUPE ORTIZ POOL'],
            ['account_number' => '1558515386', 'rfc' => null, 'total_to_pay' => '0.01', 'snapshot_name' => 'JOSE MIGUEL ANGEL CAB PUCH'],
            ['account_number' => '1558515453', 'rfc' => null, 'total_to_pay' => '0.01', 'snapshot_name' => 'SOFIA GUADALUPE UICAB PECH'],
        ];

        $output = $this->serializer->serialize($rows);

        $this->assertSame(file_get_contents($fixture), $output);
    }

    // ── consecutivo ──────────────────────────────────────────────────────

    /** @test */
    public function consecutivo_restarts_at_one_per_serialize_call_and_increments(): void
    {
        $rows = [
            $this->row(['snapshot_name' => 'A']),
            $this->row(['snapshot_name' => 'B']),
            $this->row(['snapshot_name' => 'C']),
        ];

        $output = $this->serializer->serialize($rows);
        $records = $this->splitRecords($output);

        $this->assertSame('000000001', substr($records[0], 0, 9));
        $this->assertSame('000000002', substr($records[1], 0, 9));
        $this->assertSame('000000003', substr($records[2], 0, 9));
    }

    /** @test */
    public function a_fresh_serialize_call_restarts_consecutivo_at_one(): void
    {
        // Prove there is no hidden cross-call state on the serializer instance.
        $this->serializer->serialize([$this->row([]), $this->row([]), $this->row([])]);

        $output = $this->serializer->serialize([$this->row([])]);

        $this->assertSame('000000001', substr($output, 0, 9));
    }

    // ── CRLF terminator ──────────────────────────────────────────────────

    /** @test */
    public function every_record_is_terminated_by_crlf(): void
    {
        $output = $this->serializer->serialize([$this->row([]), $this->row([])]);

        $this->assertSame("\r\n", substr($output, 108, 2));
        $this->assertSame("\r\n", substr($output, -2));
        $this->assertSame(220, strlen($output));
    }

    // ── RFC field ────────────────────────────────────────────────────────

    /** @test */
    public function blank_rfc_becomes_sixteen_spaces(): void
    {
        $output = $this->serializer->serialize([$this->row(['rfc' => null])]);

        $this->assertSame(str_repeat(' ', 16), substr($output, 9, 16));
    }

    /** @test */
    public function rfc_of_thirteen_real_chars_is_left_justified_and_space_padded(): void
    {
        $output = $this->serializer->serialize([$this->row(['rfc' => 'PEPJ800101ABC'])]);

        $this->assertSame('PEPJ800101ABC   ', substr($output, 9, 16));
    }

    // ── cuenta field ─────────────────────────────────────────────────────

    /** @test */
    public function nine_digit_account_number_is_zero_padded_to_ten_then_space_padded_to_twenty(): void
    {
        $output = $this->serializer->serialize([$this->row(['account_number' => '123456789'])]);

        $this->assertSame('0123456789          ', substr($output, 27, 20));
    }

    /** @test */
    public function ten_digit_account_number_is_only_space_padded_to_twenty(): void
    {
        $output = $this->serializer->serialize([$this->row(['account_number' => '1558515343'])]);

        $this->assertSame('1558515343          ', substr($output, 27, 20));
    }

    /**
     * Documents the pre-validated-input assumption: BankDataValidator (PR1)
     * rejects anything outside 9-10 digits before a row ever reaches this
     * class, so the serializer does not defend against it. str_pad() never
     * shortens a string already longer than its target length, so an
     * out-of-contract 11-digit value is passed through unchanged by the
     * left-zero-pad step and simply consumes more of the 20-char Cuenta
     * field — it is neither truncated nor rejected here.
     *
     * @test
     */
    public function eleven_digit_account_number_is_not_truncated_serializer_trusts_input(): void
    {
        $output = $this->serializer->serialize([$this->row(['account_number' => '15585153431'])]);

        $this->assertSame('15585153431         ', substr($output, 27, 20));
    }

    // ── importe field / centavos conversion (design D7) ─────────────────

    /** @test */
    public function centavos_conversion_matches_expected_values(): void
    {
        $cases = [
            '140.00'   => '000000000014000',
            '1400.00'  => '000000000140000',
            '2600.02'  => '000000000260002',
            '0.10'     => '000000000000010',
            '1200.00'  => '000000000120000',
            '0.01'     => '000000000000001',
        ];

        foreach ($cases as $totalToPay => $expectedImporte) {
            $output = $this->serializer->serialize([$this->row(['total_to_pay' => $totalToPay])]);

            $this->assertSame($expectedImporte, substr($output, 47, 15), "Failed for total_to_pay={$totalToPay}");
        }
    }

    /**
     * Dedicated proof that string-based centavos conversion is required.
     * Verified experimentally on this PHP build: '19.99' and '0.29' are
     * concrete values where the naive
     * `(int) ((float) $totalToPay * 100)` approach drifts by one centavo
     * (1998 instead of 1999; 28 instead of 29) because '19.99' * 100 is
     * represented internally as 1998.999999999999772... — (int) truncates
     * toward zero and loses the missing fraction. The string-based
     * conversion this serializer uses is exact because it never leaves
     * decimal representation.
     *
     * (Note: the specific '140.00' example sometimes cited for this bug
     * class does not reproduce on this PHP build/version — float rounding
     * behavior is build-dependent, which is exactly why this test proves
     * the bug with a value verified to fail here, rather than trusting a
     * borrowed example.)
     *
     * @test
     */
    public function string_based_centavos_conversion_avoids_the_float_drift_that_would_mispay_a_becario(): void
    {
        $driftingValues = ['19.99' => 1999, '0.29' => 29];

        foreach ($driftingValues as $totalToPay => $correctCentavos) {
            $floatApproach = (int) ((float) $totalToPay * 100);
            $this->assertNotSame(
                $correctCentavos,
                $floatApproach,
                "Expected float-multiplication approach to drift for {$totalToPay}, but it did not on this build — pick a new proof value."
            );

            $output = $this->serializer->serialize([$this->row(['total_to_pay' => $totalToPay])]);
            $importe = (int) substr($output, 47, 15);

            $this->assertSame($correctCentavos, $importe);
        }
    }

    // ── nombre field / normalizeName() ──────────────────────────────────

    /** @test */
    public function every_accented_character_in_the_removetrash_map_is_stripped(): void
    {
        $output = $this->serializer->serialize([$this->row([
            'snapshot_name' => 'áéíóú ÁÉÍÓÚ ñÑ üÜ',
        ])]);

        $nombre = substr($output, 62, 40);

        $this->assertSame(str_pad('AEIOU AEIOU NN UU', 40, ' ', STR_PAD_RIGHT), $nombre);
    }

    /** @test */
    public function periods_are_replaced_by_spaces(): void
    {
        $output = $this->serializer->serialize([$this->row([
            'snapshot_name' => 'JUAN P. GOMEZ M.',
        ])]);

        $nombre = substr($output, 62, 40);

        $this->assertSame(str_pad('JUAN P  GOMEZ M ', 40, ' ', STR_PAD_RIGHT), $nombre);
    }

    /** @test */
    public function name_longer_than_forty_chars_is_truncated(): void
    {
        $longName = 'MARIA GUADALUPE DE LOS ANGELES HERNANDEZ RODRIGUEZ';
        $this->assertGreaterThan(40, strlen($longName));

        $output = $this->serializer->serialize([$this->row(['snapshot_name' => $longName])]);

        $nombre = substr($output, 62, 40);

        $this->assertSame(mb_substr($longName, 0, 40), $nombre);
        $this->assertSame(40, strlen($nombre));
    }

    /** @test */
    public function short_name_is_space_padded_to_forty(): void
    {
        $output = $this->serializer->serialize([$this->row(['snapshot_name' => 'ANA LI'])]);

        $nombre = substr($output, 62, 40);

        $this->assertSame(str_pad('ANA LI', 40, ' ', STR_PAD_RIGHT), $nombre);
    }

    // ── constant fields ──────────────────────────────────────────────────

    /** @test */
    public function tipo_banco_and_plaza_are_the_expected_constants(): void
    {
        $output = $this->serializer->serialize([$this->row([])]);

        $this->assertSame('99', substr($output, 25, 2));
        $this->assertSame('001', substr($output, 102, 3));
        $this->assertSame('001', substr($output, 105, 3));
    }

    /** @test */
    public function every_record_is_exactly_one_hundred_eight_chars_before_crlf(): void
    {
        $output = $this->serializer->serialize([$this->row([])]);
        $records = $this->splitRecords($output);

        $this->assertSame(108, strlen($records[0]));
    }

    /** @test */
    public function empty_rows_array_produces_empty_string(): void
    {
        $this->assertSame('', $this->serializer->serialize([]));
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $overrides
     * @return array{account_number: ?string, rfc: ?string, total_to_pay: string, snapshot_name: string}
     */
    private function row(array $overrides): array
    {
        return array_merge([
            'account_number' => '1558515343',
            'rfc'            => null,
            'total_to_pay'   => '100.00',
            'snapshot_name'  => 'ALAN JESUS DZUL MAY',
        ], $overrides);
    }

    /** @return array<int, string> */
    private function splitRecords(string $output): array
    {
        return array_values(array_filter(explode("\r\n", $output), fn ($r) => $r !== ''));
    }
}
