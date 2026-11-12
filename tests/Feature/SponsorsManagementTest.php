<?php

namespace Tests\Feature;

use App\Models\AceSponsor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SponsorsManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_only_sees_published_sponsors_without_internal_paths(): void
    {
        AceSponsor::create(['name' => 'Empresa publicada', 'logo_path' => 'ace/sponsors/public.png', 'is_active' => true]);
        AceSponsor::create(['name' => 'Empresa oculta', 'logo_path' => 'ace/sponsors/hidden.png', 'is_active' => false]);
        $response = $this->getJson('/api/v1/public/sponsors')->assertOk();
        $response->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Empresa publicada');
        $response->assertDontSee('logo_path')->assertDontSee('Empresa oculta');
    }

    public function test_customer_cannot_manage_global_sponsors(): void
    {
        $user = User::factory()->create(['is_dev' => false, 'is_active' => true]);
        $this->actingAs($user)->getJson('/api/v1/dev/sponsors')->assertForbidden();
        $this->actingAs($user)->postJson('/api/v1/dev/sponsors', ['name'=>'Outra empresa'])->assertForbidden();
    }

    public function test_dev_can_publish_hide_and_soft_delete_sponsors(): void
    {
        Storage::fake('local');
        $dev = User::factory()->create(['is_dev' => true, 'is_active' => true]);
        $response = $this->actingAs($dev)->post('/api/v1/dev/sponsors', [
            'name' => 'Patrocinador teste', 'logo' => UploadedFile::fake()->image('logo.png'),
            'website_url' => 'https://example.org', 'sort_order' => 2, 'is_active' => '1',
        ]);
        $response->assertCreated();
        $sponsor = AceSponsor::firstOrFail();
        Storage::disk('local')->assertExists($sponsor->logo_path);
        $this->getJson('/api/v1/public/sponsors')->assertJsonCount(1, 'data');
        $this->actingAs($dev)->post('/api/v1/dev/sponsors/'.$sponsor->id, ['is_active'=>'0'])->assertOk();
        $this->getJson('/api/v1/public/sponsors')->assertJsonCount(0, 'data');
        $this->actingAs($dev)->deleteJson('/api/v1/dev/sponsors/'.$sponsor->id)->assertOk();
        $this->assertSoftDeleted('ace_sponsors', ['id' => $sponsor->id]);
        Storage::disk('local')->assertExists($sponsor->logo_path);
    }
}
