<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

pest()->use(RefreshDatabase::class)->group('pest-features');

dataset('protected feature endpoints', [
    'profile details' => ['GET', 'auth.me'],
    'home' => ['GET', 'home.show'],
    'areas' => ['GET', 'area.index'],
    'create area' => ['POST', 'area.store'],
    'projects' => ['GET', 'project.index'],
    'create project' => ['POST', 'project.store'],
    'boards' => ['GET', 'board.index'],
    'create board' => ['POST', 'board.store'],
    'resources' => ['GET', 'resource.index'],
    'create resource' => ['POST', 'resource.store'],
    'resource tags' => ['GET', 'resource.tags'],
    'notes' => ['GET', 'notes.index'],
    'note tree' => ['GET', 'notes.tree'],
    'create note' => ['POST', 'notes.store'],
    'journal' => ['GET', 'journal.index'],
    'create journal entry' => ['POST', 'journal.store'],
    'letters' => ['GET', 'letters.index'],
    'create letter' => ['POST', 'letters.store'],
    'habits' => ['GET', 'habits.index'],
    'habit calendar' => ['GET', 'habits.calendar'],
    'create habit' => ['POST', 'habits.store'],
    'focus dashboard' => ['GET', 'focus.show'],
    'focus tasks' => ['GET', 'focus.tasks.index'],
    'linkable focus tasks' => ['GET', 'focus.linkable-tasks'],
    'create focus task' => ['POST', 'focus.tasks.store'],
    'start focus session' => ['POST', 'focus.sessions.store'],
    'calendar' => ['GET', 'calendar.plans.index'],
    'upcoming calendar' => ['GET', 'calendar.plans.upcoming'],
    'create calendar plan' => ['POST', 'calendar.plans.store'],
    'notifications' => ['GET', 'notifications.index'],
    'search' => ['GET', 'search.index'],
    'trash' => ['GET', 'trash.index'],
    'preferences' => ['PATCH', 'settings.preferences.update'],
    'profile image upload' => ['POST', 'profile.image.store'],
    'profile image removal' => ['DELETE', 'profile.image.destroy'],
    'password change' => ['PATCH', 'profile.password.update'],
    'account deletion' => ['DELETE', 'profile.destroy'],
]);

it('returns 401 for a guest accessing a protected feature', function (string $method, string $name): void {
    $this->json($method, route($name))->assertUnauthorized()->assertJsonPath('status', 401);
    $this->assertDatabaseEmpty('projects');
    $this->assertDatabaseEmpty('notes');
})->with('protected feature endpoints');

it('returns 403 before an unverified account can read or mutate a feature', function (string $method, string $name): void {
    $user = User::factory()->unverified()->create();
    Passport::actingAs($user);

    $this->json($method, route($name))->assertForbidden()
        ->assertJsonPath('message', 'Please verify your email address before accessing the app.');
    $this->assertModelExists($user);
    $this->assertDatabaseEmpty('projects');
    $this->assertDatabaseEmpty('notes');
})->with('protected feature endpoints');

it('returns 422 without persisting a feature with missing required fields', function (
    string $method,
    string $name,
    array $fields,
    string $table,
): void {
    Passport::actingAs(User::factory()->create());

    $this->json($method, route($name))->assertUnprocessable()->assertJsonValidationErrors($fields);
    $this->assertDatabaseEmpty($table);
})->with([
    'area' => ['POST', 'area.store', ['name'], 'areas'],
    'project' => ['POST', 'project.store', ['name'], 'projects'],
    'resource' => ['POST', 'resource.store', ['title'], 'resources'],
    'note' => ['POST', 'notes.store', ['title', 'content'], 'notes'],
    'journal' => ['POST', 'journal.store', ['title', 'content'], 'journal_entries'],
    'letter' => ['POST', 'letters.store', ['title', 'content'], 'letters'],
    'habit' => ['POST', 'habits.store', ['name'], 'habits'],
    'focus task' => ['POST', 'focus.tasks.store', ['title'], 'focus_tasks'],
    'focus session' => ['POST', 'focus.sessions.store', ['type'], 'focus_sessions'],
    'calendar plan' => ['POST', 'calendar.plans.store', ['title', 'date', 'timezone', 'is_all_day'], 'calendar_plans'],
]);
