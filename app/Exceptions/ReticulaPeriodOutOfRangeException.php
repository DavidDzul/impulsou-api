<?php

namespace App\Exceptions;

/**
 * sdd/verificacion-incidencias-ux, PR1: thrown ONLY by
 * GenerateMonthlyRefrendsService::assertPeriodWithinReticula() in place of
 * the plain \DomainException it used to throw, with the EXACT SAME Spanish
 * messages. Extending \DomainException (not just \Exception) means every
 * existing `catch (\DomainException $e)` call site (generateForUser's 422
 * response, RecordAdvancePaymentAction, and any `expectException(\DomainException::class)`
 * test) keeps matching unchanged. generateForPeriod()'s bulk loop uses
 * `instanceof self` to classify this one specific case as
 * `skipped_out_of_reticula` instead of `errors`, without changing any
 * message text — message matching is brittle and out of scope here.
 */
class ReticulaPeriodOutOfRangeException extends \DomainException
{
}
