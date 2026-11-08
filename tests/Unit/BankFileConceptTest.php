<?php

namespace Tests\Unit;

use App\Enums\BankFileConcept;
use PHPUnit\Framework\TestCase;

/**
 * sdd/archivo-pago-beca-apoyo (PR2, task 2.1): the two bank-file concepts.
 */
class BankFileConceptTest extends TestCase
{
    /** @test */
    public function beca_exposes_slug_suffix_and_amount_key(): void
    {
        $this->assertSame('beca', BankFileConcept::BECA->slug());
        $this->assertSame('BECA', BankFileConcept::BECA->fileSuffix());
        $this->assertSame('beca_amount', BankFileConcept::BECA->amountKey());
    }

    /** @test */
    public function apoyo_exposes_slug_suffix_and_amount_key(): void
    {
        $this->assertSame('apoyo', BankFileConcept::APOYO->slug());
        $this->assertSame('APOYO', BankFileConcept::APOYO->fileSuffix());
        $this->assertSame('apoyo_amount', BankFileConcept::APOYO->amountKey());
    }

    /** @test */
    public function slugs_lists_both_route_values_in_order(): void
    {
        $this->assertSame(['beca', 'apoyo'], BankFileConcept::slugs());
    }

    /** @test */
    public function from_slug_resolves_and_rejects_unknown(): void
    {
        $this->assertSame(BankFileConcept::APOYO, BankFileConcept::fromSlug('apoyo'));
        $this->assertNull(BankFileConcept::fromSlug('combined'));
    }
}
