<?php

namespace App\Exceptions;

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
 */
class ScholarshipCycleClosedException extends \DomainException
{
}
