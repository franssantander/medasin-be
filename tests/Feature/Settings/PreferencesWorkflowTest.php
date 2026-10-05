<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

pest()->use(RefreshDatabase::class)->group('pest-features');

it('retains independent font preferences when accounts share a browser', function (): void {
    $first = User::factory()->create();
    $second = User::factory()->create();
    Passport::actingAs($first);
    $this->patchJson(route('settings.preferences.update'), ['font_family' => 'geist'])->assertOk();
    Passport::actingAs($second);
    $this->getJson(route('auth.me'))->assertOk()->assertJsonPath('data.font_family', 'manrope');
    $this->patchJson(route('settings.preferences.update'), ['font_family' => 'inter'])->assertOk();

    Passport::actingAs($first);
    $this->getJson(route('auth.me'))->assertOk()->assertJsonPath('data.font_family', 'geist');
    Passport::actingAs($second);
    $this->getJson(route('auth.me'))->assertOk()->assertJsonPath('data.font_family', 'inter');

    $this->assertDatabaseHas('users', ['id' => $first->id, 'font_family' => 'geist']);
    $this->assertDatabaseHas('users', ['id' => $second->id, 'font_family' => 'inter']);
});
