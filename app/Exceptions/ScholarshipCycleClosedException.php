<?php

namespace App\Exceptions;

use App\Exceptions\Contracts\CarriesErrorPayload;

/**
 * sdd/egresado-baja-definitiva, design D3 — thrown by
 * GenerateMonthlyRefrendsService::assertCycleOpen() when a profile's
 * cycle_closed_period stop marker (design D1/D2) is set and the requested
 * period falls after it. Extends \DomainException so every existing
 * `catch (\DomainException $e)` call site (generateForUser's 422 response,
 * RecordAdvancePaymentAction) keeps matching unchanged.
 * generateForPeriod()'s bulk loop uses `instanceof self` to classify this
 * specific case as a silent skip (no failure entry, no Log::error) — a cut
 * becario is an EXPECTED skip, not an error.
 *
 * PR2b (guard #0 of 4, evaluated first): also thrown by
 * CloseScholarshipCycleAction when a second cut is attempted on a profile
 * that already has a stop marker. Implements CarriesErrorPayload so the
 * controller's 422 body is shaped consistently with the other 3 cut guards,
 * even though this one carries no extra data (there is nothing to list —
 * the becario is already fully closed).
 */
class ScholarshipCycleClosedException extends \DomainException implements CarriesErrorPayload
{
    public const CODE = 'CYCLE_ALREADY_CLOSED';

    public function code(): string
    {
        return self::CODE;
    }

    public function payload(): array
    {
        return [];
    }
}
