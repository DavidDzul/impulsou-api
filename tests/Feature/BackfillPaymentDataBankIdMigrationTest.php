<?php

namespace Tests\Feature;

use App\Models\Bank;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * sdd/catalogo-bancos R4: legacy bank_name variants of BBVA are linked to the
 * BBVA bank; everything else stays without bank. Idempotent.
 */
class BackfillPaymentDataBankIdMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const PATH = __DIR__ . '/../../database/migrations/2026_11_01_100003_backfill_payment_data_bank_id.php';

    private function migration(): \Illuminate\Database\Migrations\Migration
    {
        return require self::PATH;
    }

    private function row(?string $bankName, string $method = 'TRANSFER', ?int $bankId = null): int
    {
        $user = User::factory()->create();

        return DB::table('scholarship_payment_data')->insertGetId([
            'user_id'        => $user->id,
            'payment_method' => $method,
            'bank_id'        => $bankId,
            'bank_name'      => $bankName,
            'account_number' => $method === 'CHEQUE' ? null : '1234567890',
            'curp'           => 'ABCD010101HDFRRL09',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
    }

    private function bankIdOf(int $rowId): ?int
    {
        $value = DB::table('scholarship_payment_data')->where('id', $rowId)->value('bank_id');

        return $value === null ? null : (int) $value;
    }

    /** @test */
    public function it_links_bbva_aliases_regardless_of_case_accents_and_spacing(): void
    {
        $bbva = (int) Bank::where('code', '012')->value('id');
        $aliases = ['BBVA', 'bbva', 'Bancomer', ' BBVA  Bancomer ', 'BBVA México', 'bbva mexico', 'BANCOMER S.A.', 'B.B.V.A.'];
        $ids = array_map(fn ($alias) => $this->row($alias), $aliases);

        $this->migration()->up();

        foreach ($ids as $i => $id) {
            $this->assertSame($bbva, $this->bankIdOf($id), "alias [{$aliases[$i]}] should map to BBVA");
        }
    }

    /** @test */
    public function it_leaves_other_banks_cheques_and_blank_names_without_bank(): void
    {
        $other = $this->row('Santander');
        $blank = $this->row(null);
        $cheque = $this->row('BBVA', 'CHEQUE');

        $this->migration()->up();

        $this->assertNull($this->bankIdOf($other));
        $this->assertNull($this->bankIdOf($blank));
        $this->assertNull($this->bankIdOf($cheque));
        $this->assertSame('Santander', DB::table('scholarship_payment_data')->where('id', $other)->value('bank_name'));
    }

    /** @test */
    public function it_is_idempotent_and_never_overrides_an_existing_bank(): void
    {
        $banorte = (int) Bank::where('code', '072')->value('id');
        $kept = $this->row('BBVA', 'TRANSFER', $banorte);
        $fresh = $this->row('Bancomer');

        $this->migration()->up();
        $this->migration()->up();

        $this->assertSame($banorte, $this->bankIdOf($kept));
        $this->assertSame((int) Bank::where('code', '012')->value('id'), $this->bankIdOf($fresh));
    }

    /** @test */
    public function down_is_a_noop_and_keeps_bank_name(): void
    {
        $id = $this->row('BBVA');
        $this->migration()->up();

        $this->migration()->down();

        $this->assertSame('BBVA', DB::table('scholarship_payment_data')->where('id', $id)->value('bank_name'));
    }
}
