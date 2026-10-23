<?php
namespace Tests\Feature;
use Tests\TestCase;
class EntryTest extends TestCase
{
    public function test_spa_entry_renders(): void { $this->get('/')->assertOk()->assertSee('id="app"', false); }
}
