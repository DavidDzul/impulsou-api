<?php

namespace Tests\Concerns;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Support\Facades\DB;

/**
 * Records the order in which a code path takes row locks / writes rows.
 *
 * The test DB is SQLite, whose grammar silently drops FOR UPDATE, so a real
 * deadlock cannot be reproduced. Instead the connection grammar is swapped for
 * one that appends a harmless SQL comment where MySQL would emit the lock,
 * and DB::listen captures every statement. Tests then assert the ORDER.
 */
trait RecordsLockOrder
{
    /** @var array<int, array{kind: string, table: string}> */
    private array $lockEvents = [];

    protected function startRecordingLockOrder(): void
    {
        $this->lockEvents = [];

        /** @var Connection $connection */
        $connection = DB::connection();
        $connection->setQueryGrammar(new class extends SQLiteGrammar {
            protected function compileLock(Builder $query, $value)
            {
                return $value ? ' /* for update */' : '';
            }
        });

        DB::listen(function (QueryExecuted $query) {
            $event = $this->classifyStatement($query->sql);
            if ($event !== null) {
                $this->lockEvents[] = $event;
            }
        });
    }

    /** @return array<int, array{kind: string, table: string}> */
    protected function recordedLockEvents(): array
    {
        return $this->lockEvents;
    }

    /**
     * Asserts that the first row lock taken targets $table and that nothing
     * else was locked or written before it (writes to $table itself are fine).
     */
    protected function assertUsersRowLockedFirst(string $table = 'users'): void
    {
        $events = $this->lockEvents;
        $first  = null;
        foreach ($events as $i => $event) {
            if ($event['kind'] === 'lock' && $event['table'] === $table) {
                $first = $i;
                break;
            }
        }

        $this->assertNotNull($first, "No FOR UPDATE on {$table} recorded. Events: " . json_encode($events));

        foreach (array_slice($events, 0, $first) as $event) {
            $this->assertSame(
                $table,
                $event['table'],
                "{$event['kind']} on {$event['table']} happened before the {$table} row lock. Events: " . json_encode($events)
            );
        }
    }

    /** @return array{kind: string, table: string}|null */
    private function classifyStatement(string $sql): ?array
    {
        $sql = trim($sql);

        if (stripos($sql, 'select') === 0 && stripos($sql, '/* for update */') !== false
            && preg_match('/\bfrom\s+"?(\w+)"?/i', $sql, $m)) {
            return ['kind' => 'lock', 'table' => $m[1]];
        }

        if (preg_match('/^(?:insert\s+into|update|delete\s+from)\s+"?(\w+)"?/i', $sql, $m)) {
            return ['kind' => 'write', 'table' => $m[1]];
        }

        return null;
    }
}
