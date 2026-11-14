<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddUniqueCampusGenerationDateToClasses extends Migration
{
    private const INDEX = 'classes_campus_generation_id_date_unique';

    public function up()
    {
        $duplicates = $this->findDuplicateGroups();

        // Abort BEFORE any DDL so a failed run leaves the schema untouched.
        if ($duplicates !== []) {
            throw new RuntimeException(sprintf(
                'Cannot add unique index %s: %d duplicate group(s): %s. Resolve them first (staging: run docs/deploy-staging/18).',
                self::INDEX,
                count($duplicates),
                implode(' ', $duplicates)
            ));
        }

        Schema::table('classes', function (Blueprint $table) {
            $table->unique(['campus', 'generation_id', 'date'], self::INDEX);
        });
    }

    public function down()
    {
        Schema::table('classes', function (Blueprint $table) {
            $table->dropUnique(self::INDEX);
        });
    }

    /**
     * Portable (no GROUP_CONCAT): collect ids per (campus, generation_id, date) in PHP.
     *
     * @return string[] e.g. ["MERIDA/1/2025-11-21 ids [7,8]"]
     */
    private function findDuplicateGroups(): array
    {
        $groups = DB::table('classes')
            ->select('id', 'campus', 'generation_id', 'date')
            ->orderBy('id')
            ->get()
            ->groupBy(fn ($row) => $row->campus . '/' . $row->generation_id . '/' . substr((string) $row->date, 0, 10));

        return $groups
            ->filter(fn ($rows) => $rows->count() > 1)
            ->map(fn ($rows, $key) => $key . ' ids [' . $rows->pluck('id')->implode(',') . ']')
            ->values()
            ->all();
    }
}
