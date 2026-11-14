<?php

namespace Tests\Feature;

use App\Models\Generation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * sdd/clases-filtro-periodo-asignacion-manual, class-uniqueness R3.3-R3.4.
 */
class ClassesUniqueIndexMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const INDEX = 'classes_campus_generation_id_date_unique';

    private int $generationId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generationId = Generation::create(['generation_name' => 'G1', 'campus' => 'MERIDA', 'generation_active' => true])->id;
    }

    private function migration(): object
    {
        require_once database_path('migrations/2026_11_14_100000_add_unique_campus_generation_date_to_classes.php');

        return new \AddUniqueCampusGenerationDateToClasses();
    }

    private function insertClass(string $date): int
    {
        return DB::table('classes')->insertGetId([
            'name' => 'C',
            'date' => $date,
            'start_time' => '09:00:00',
            'end_time' => '11:00:00',
            'campus' => 'MERIDA',
            'generation_id' => $this->generationId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function indexExists(): bool
    {
        return collect(Schema::getConnection()->getDoctrineSchemaManager()->listTableIndexes('classes'))
            ->has(self::INDEX);
    }

    /** @test */
    public function up_aborts_listing_duplicates_without_creating_the_index(): void
    {
        $this->migration()->down();
        $a = $this->insertClass('2026-11-20');
        $b = $this->insertClass('2026-11-20');

        try {
            $this->migration()->up();
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('1 duplicate group', $e->getMessage());
            $this->assertStringContainsString("[$a,$b]", $e->getMessage());
        }

        $this->assertFalse($this->indexExists());
    }

    /** @test */
    public function up_and_down_create_and_drop_the_index(): void
    {
        $migration = $this->migration();
        $this->assertTrue($this->indexExists());

        $migration->down();
        $this->assertFalse($this->indexExists());

        $migration->up();
        $this->assertTrue($this->indexExists());
    }
}
