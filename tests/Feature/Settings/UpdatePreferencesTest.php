<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UpdatePreferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_accounts_default_to_manrope_in_the_database_and_profile(): void
    {
        $user = User::factory()->create()->refresh();
        Passport::actingAs($user);

        $this->getJson(route('auth.me'))->assertOk()->assertJsonPath('data.font_family', 'manrope');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'font_family' => 'manrope']);
    }

    /** @return array<string, array{string}> */
    public static function supportedFonts(): array
    {
        return [
            'default' => ['manrope'],
            'geist' => ['geist'],
            'inter' => ['inter'],
        ];
    }

    #[DataProvider('supportedFonts')]
    public function test_saves_supported_fonts_and_returns_the_persisted_preference(string $font): void
    {
        $user = User::factory()->create();
        $user->font_family = 'inter';
        $user->save();
        Passport::actingAs($user);

        $this->patchJson(route('settings.preferences.update'), ['font_family' => $font])
            ->assertOk()->assertJsonPath('data.font_family', $font)
            ->assertJsonPath('message', 'Font preference saved.');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'font_family' => $font]);
        $this->getJson(route('auth.me'))->assertOk()->assertJsonPath('data.font_family', $font);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidFonts(): array
    {
        return [
            'missing' => [[], 'Choose a font.'],
            'null' => [['font_family' => null], 'Choose a font.'],
            'empty' => [['font_family' => ''], 'Choose a font.'],
            'unsupported' => [['font_family' => 'serif'], 'Choose Manrope, Geist, or Inter.'],
            'wrong case' => [['font_family' => 'Geist'], 'Choose Manrope, Geist, or Inter.'],
            'css value' => [['font_family' => 'url(https://example.com/font)'], 'Choose Manrope, Geist, or Inter.'],
            'number' => [['font_family' => 42], 'Choose a valid font.'],
            'array' => [['font_family' => ['geist']], 'Choose a valid font.'],
        ];
    }

    #[DataProvider('invalidFonts')]
    public function test_returns_422_without_changing_the_saved_font_for_invalid_input(array $payload, string $message): void
    {
        $user = User::factory()->create()->refresh();
        Passport::actingAs($user);

        $this->patchJson(route('settings.preferences.update'), $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('font_family')
            ->assertJsonPath('errors.font_family.0', $message);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'font_family' => 'manrope']);
    }

    public function test_returns_401_without_a_session_and_does_not_save_a_font(): void
    {
        $user = User::factory()->create();

        $this->patchJson(route('settings.preferences.update'), ['font_family' => 'geist'])
            ->assertUnauthorized();

        $this->assertDatabaseHas('users', ['id' => $user->id, 'font_family' => 'manrope']);
    }

    public function test_returns_403_for_an_unverified_account_without_changing_its_font(): void
    {
        $user = User::factory()->unverified()->create();
        Passport::actingAs($user);

        $this->patchJson(route('settings.preferences.update'), ['font_family' => 'geist'])
            ->assertForbidden()->assertJsonPath('message', 'Please verify your email address before accessing the app.');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'font_family' => 'manrope']);
    }

    public function test_updates_only_the_authenticated_users_font_and_ignores_unrelated_attributes(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        Passport::actingAs($user);

        $this->patchJson(route('settings.preferences.update'), [
            'font_family' => 'geist',
            'user_id' => $other->id,
            'id' => $other->id,
            'first_name' => 'Changed',
            'email' => 'changed@example.com',
        ])->assertOk()->assertJsonPath('data.font_family', 'geist');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'font_family' => 'geist',
            'first_name' => $user->first_name,
            'email' => $user->email,
        ]);
        $this->assertDatabaseHas('users', ['id' => $other->id, 'font_family' => 'manrope']);
    }
}
