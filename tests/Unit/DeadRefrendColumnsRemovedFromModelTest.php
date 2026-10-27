<?php

namespace Tests\Unit;

use App\Models\ScholarshipRefrend;
use Tests\TestCase;

/**
 * sdd/limpieza-refrendos, A3: the 4 dead columns (schema still present until
 * A4) must not be reachable from the model: not mass-assignable, not cast,
 * and their relations are gone (transitions() pointed at a missing table).
 */
class DeadRefrendColumnsRemovedFromModelTest extends TestCase
{
    private const DEAD_COLUMNS = [
        'pedagogia_resolved_by_id',
        'pedagogia_resolved_at',
        'notified_by_id',
        'missing_subjects_snapshot',
    ];

    /** @test */
    public function fillable_excludes_the_dead_columns(): void
    {
        $fillable = (new ScholarshipRefrend())->getFillable();

        foreach (self::DEAD_COLUMNS as $column) {
            $this->assertNotContains($column, $fillable, "{$column} must not be fillable");
        }
    }

    /** @test */
    public function casts_exclude_the_dead_columns(): void
    {
        $casts = (new ScholarshipRefrend())->getCasts();

        foreach (self::DEAD_COLUMNS as $column) {
            $this->assertArrayNotHasKey($column, $casts, "{$column} must not be cast");
        }
    }

    /** @test */
    public function dead_relations_no_longer_exist(): void
    {
        $model = new ScholarshipRefrend();

        foreach (['notifiedBy', 'pedagogiaResolvedBy', 'transitions'] as $relation) {
            $this->assertFalse(method_exists($model, $relation), "{$relation}() must be removed");
            $this->assertFalse($model->relationLoaded($relation));
        }
    }
}
