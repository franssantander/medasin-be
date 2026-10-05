<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;

pest()->use(RefreshDatabase::class)->group('pest-features');

it('removes a profile image from responses before its queued file cleanup runs', function (): void {
    Storage::fake('public');
    $user = User::factory()->create();
    Passport::actingAs($user);
    $this->postJson(route('profile.image.store'), [
        'image' => UploadedFile::fake()->create('avatar.png', 12, 'image/png'),
    ])->assertOk();
    $path = $user->refresh()->profile_image_path;
    Storage::disk('public')->assertExists($path);
    $this->getJson(route('auth.me'))->assertOk()
        ->assertJsonPath('data.profile_image_url', url(Storage::disk('public')->url($path)));

    $this->deleteJson(route('profile.image.destroy'))->assertOk()->assertJsonPath('data.profile_image_url', null);
    $user->refresh();
    $this->getJson(route('auth.me'))->assertOk()->assertJsonPath('data.profile_image_url', null);
    $this->assertDatabaseHas('users', ['id' => $user->id, 'profile_image_path' => null]);
    $this->assertDatabaseCount('jobs', 1);
    Storage::disk('public')->assertExists($path);

    $job = Queue::connection('database')->pop();
    expect($job)->not->toBeNull();
    $job->fire();
    $job->delete();

    Storage::disk('public')->assertMissing($path);
    $this->assertDatabaseEmpty('jobs');
});
