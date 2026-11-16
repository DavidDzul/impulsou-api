<?php

namespace App\Support;

use Illuminate\Database\QueryException;

class UniqueViolation
{
    /** True when the query exception is a duplicate-key violation (MySQL, SQLite, Postgres). */
    public static function matches(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? '');
        $message = $e->getMessage();

        return in_array($sqlState, ['23000', '23505'], true)
            || stripos($message, 'duplicate entry') !== false
            || stripos($message, 'unique constraint') !== false;
    }
}
