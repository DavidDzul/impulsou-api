<?php

namespace Tests\Feature;

use App\Models\ScholarshipProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `POST scholarship-profiles/{userId}/reticula` (constancia o malla curricular):
 * the document is REQUIRED only while the profile has no stored document;
 * once one exists, uploading a new one is optional (it replaces the old one).
 */
class ScholarshipProfileReticulaUploadTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->admin = User::factory()->create(['user_type' => 'ADMIN', 'active' => true]);
    }

    private function profile(array $overrides = []): ScholarshipProfile
    {
        $user = User::factory()->create(['user_type' => 'BEC_ACTIVE', 'active' => true]);

        return ScholarshipProfile::factory()->create(array_merge(
            ['user_id' => $user->id],
            $overrides
        ));
    }

    private function url(ScholarshipProfile $profile): string
    {
        return "/api/admin/scholarship-profiles/{$profile->user_id}/reticula";
    }

    private function dates(): array
    {
        return [
            'reticula_start_date' => '2024-08-01',
            'reticula_end_date'   => '2028-06-30',
        ];
    }

    /** @test */
    public function it_rejects_a_request_without_file_when_no_document_is_stored(): void
    {
        $profile = $this->profile(['reticula_file_path' => null]);

        $response = $this->actingAs($this->admin)->postJson($this->url($profile), $this->dates());

        $response->assertStatus(422)
            ->assertJsonValidationErrors('file')
            ->assertJsonPath('errors.file.0', 'El documento es obligatorio.');
        $this->assertNull($profile->fresh()->reticula_file_path);
    }

    /** @test */
    public function it_stores_the_file_when_no_document_is_stored(): void
    {
        $profile = $this->profile(['reticula_file_path' => null]);

        $response = $this->actingAs($this->admin)->post($this->url($profile), $this->dates() + [
            'file' => UploadedFile::fake()->create('constancia.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json']);

        $response->assertOk();
        $path = $profile->fresh()->reticula_file_path;
        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);
        $this->assertSame('constancia.pdf', $profile->fresh()->reticula_original_name);
    }

    /** @test */
    public function it_updates_dates_and_keeps_the_stored_document_when_no_file_is_sent(): void
    {
        $profile = $this->profile([
            'reticula_file_path'     => 'scholarships/1/reticula/old.pdf',
            'reticula_original_name' => 'old.pdf',
        ]);

        $response = $this->actingAs($this->admin)->postJson($this->url($profile), $this->dates());

        $response->assertOk();
        $fresh = $profile->fresh();
        $this->assertSame('2024-08-01', $fresh->reticula_start_date->toDateString());
        $this->assertSame('2028-06-30', $fresh->reticula_end_date->toDateString());
        $this->assertSame('scholarships/1/reticula/old.pdf', $fresh->reticula_file_path);
        $this->assertSame('old.pdf', $fresh->reticula_original_name);
    }
}
