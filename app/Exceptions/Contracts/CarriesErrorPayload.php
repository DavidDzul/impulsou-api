<?php

namespace App\Exceptions\Contracts;

/**
 * sdd/egresado-baja-definitiva, PR2b. Lets a thin controller catch block map
 * any guard exception to a consistent 422 body ({res:false, msg, code, data})
 * without a separate if/elseif per exception class. Implementors already
 * expose a CODE class constant (the project's existing convention, read via
 * `defined(get_class($e).'::CODE')`); this interface additionally guarantees
 * a `data` payload is available for the response body.
 */
interface CarriesErrorPayload
{
    public function code(): string;

    /** @return array<string, mixed> */
    public function payload(): array;
}
