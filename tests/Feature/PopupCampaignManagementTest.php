<?php

namespace Tests\Feature;

use App\Models\AcePopup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PopupCampaignManagementTest extends TestCase
{
    use RefreshDatabase; // Tests\TestCase BLOQUEIA qualquer banco que não seja SQLite em memória.

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'type' => 'text', 'title' => 'Novidade ACE', 'description' => 'Conheça os recursos.',
            'badge_text' => '', 'cta_label' => 'Conhecer', 'cta_url' => '/contratar',
            'placements' => ['home', 'login'], 'trigger' => 'delay', 'delay_seconds' => 3,
            'scroll_percent' => 40, 'frequency' => 'session', 'priority' => 12,
            'is_active' => '1', 'starts_at' => '', 'ends_at' => '',
        ], $overrides);
    }

    private function dev(): User
    {
        return User::factory()->create(['is_dev' => true, 'is_active' => true]);
    }

    public function test_only_dev_can_create_or_edit_campaigns(): void
    {
        $ordinary = User::factory()->create(['is_dev' => false, 'is_active' => true]);
        $this->actingAs($ordinary)->getJson('/api/v1/dev/popups')->assertForbidden();
        $this->actingAs($ordinary)->postJson('/api/v1/dev/popups', $this->payload())->assertForbidden();
        $this->actingAs($this->dev())->postJson('/api/v1/dev/popups', $this->payload())
            ->assertCreated()->assertJsonPath('data.type', 'text');
        $this->assertDatabaseHas('dev_admin_audits', ['action' => 'dev.popup.created.1']);
    }

    public function test_only_published_active_scheduled_campaigns_reach_correct_public_page(): void
    {
        $this->actingAs($this->dev());
        $this->postJson('/api/v1/dev/popups', $this->payload())->assertCreated();
        $this->postJson('/api/v1/dev/popups', $this->payload(['title'=>'Escondido', 'is_active'=>'0']))->assertCreated();
        $this->postJson('/api/v1/dev/popups', $this->payload(['title'=>'Futuro', 'starts_at'=>now()->addWeek()->format('Y-m-d H:i')]))->assertCreated();
        $this->getJson('/api/v1/public/popups?placement=home')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Novidade ACE')
            ->assertDontSee('Escondido')->assertDontSee('created_by')->assertDontSee('image_path');
        $this->getJson('/api/v1/public/popups?placement=signup')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/public/popups?placement=dashboard')->assertNotFound();
    }

    public function test_image_requires_upload_and_preserves_media_on_soft_delete(): void
    {
        Storage::fake('local');
        $this->actingAs($this->dev());
        $this->postJson('/api/v1/dev/popups', $this->payload(['type'=>'image']))->assertUnprocessable();
        $this->post('/api/v1/dev/popups', $this->payload([
            'type'=>'image', 'title'=>'', 'description'=>'', 'image'=>UploadedFile::fake()->image('comunicado.png'),
        ]))->assertCreated();
        $popup=AcePopup::firstOrFail();
        Storage::disk('local')->assertExists($popup->image_path);
        $this->get('/api/v1/public/popups/'.$popup->id.'/image')->assertOk();
        $this->deleteJson('/api/v1/dev/popups/'.$popup->id)->assertOk();
        $this->get('/api/v1/public/popups/'.$popup->id.'/image')->assertNotFound();
        $this->assertSoftDeleted('ace_popups', ['id'=>$popup->id]);
        Storage::disk('local')->assertExists($popup->image_path);
    }

    public function test_invalid_external_links_and_incorrect_schedule_are_rejected(): void
    {
        $this->actingAs($this->dev());
        $this->postJson('/api/v1/dev/popups', $this->payload(['cta_url'=>'javascript:alert(1)']))->assertUnprocessable();
        $this->postJson('/api/v1/dev/popups', $this->payload([
            'starts_at'=>'2026-12-15 16:00', 'ends_at'=>'2026-12-14 16:00',
        ]))->assertUnprocessable();
        $this->assertDatabaseCount('ace_popups', 0);
    }

    public function test_published_list_respects_priority_and_expiry(): void
    {
        $dev=$this->dev();
        $this->actingAs($dev)->postJson('/api/v1/dev/popups', $this->payload(['title'=>'Baixa prioridade','priority'=>1]))->assertCreated();
        $this->actingAs($dev)->postJson('/api/v1/dev/popups', $this->payload(['title'=>'Alta prioridade','priority'=>70]))->assertCreated();
        $this->actingAs($dev)->postJson('/api/v1/dev/popups', $this->payload([
            'title'=>'Vencido','priority'=>100, 'ends_at'=>now()->subDay()->format('Y-m-d H:i'),
        ]))->assertCreated();
        $this->getJson('/api/v1/public/popups?placement=home')->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonPath('data.0.title', 'Alta prioridade')
            ->assertJsonPath('data.1.title', 'Baixa prioridade');
    }
}
