<?php

use App\Models\CalendarPlan;
use App\Models\JournalEntry;
use App\Models\Letter;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Passport;
use Tests\TestCase;

pest()->use(RefreshDatabase::class)->group('pest-features');

function moduleRateLimitRecord(User $user, string $profile): Model
{
    return match ($profile) {
        'boards' => $user->boards()->create(['name' => 'Original name']),
        'focus' => $user->focusTasks()->create(['title' => 'Original title']),
        'calendar' => CalendarPlan::factory()->for($user)->create(['title' => 'Original title']),
        'journals' => JournalEntry::factory()->for($user)->create(['title' => 'Original title']),
        'letters' => Letter::factory()->for($user)->create(['title' => 'Original title']),
        'notes' => $user->standaloneNotes()->create(['title' => 'Original title', 'content' => 'Original content']),
        'project-reads' => $user->projects()->create(['name' => 'Original project']),
    };
}

function moduleRateLimitReadUrl(string $profile): string
{
    return match ($profile) {
        'boards' => route('board.index'),
        'focus' => route('focus.tasks.index'),
        'calendar' => route('calendar.plans.upcoming', ['timezone' => 'UTC']),
        'journals' => route('journal.index'),
        'letters' => route('letters.index'),
        'notes' => route('notes.index'),
        'project-reads' => route('project.index'),
    };
}

function moduleRateLimitAction(TestCase $test, string $profile, Model $record, string $value): TestResponse
{
    return match ($profile) {
        'boards' => $test->putJson(route('board.update', $record), ['name' => $value]),
        'focus' => $test->patchJson(route('focus.tasks.update', $record), ['title' => $value]),
        'calendar' => $test->putJson(route('calendar.plans.update', $record), [
            'title' => $value, 'date' => '2026-10-01', 'is_all_day' => true, 'timezone' => 'UTC',
        ]),
        'journals' => $test->patchJson(route('journal.update', $record), ['title' => $value]),
        'letters' => $test->patchJson(route('letters.update', $record), ['title' => $value]),
        'notes' => $test->patchJson(route('notes.update', $record), ['title' => $value]),
        'project-reads' => $test->getJson(route('project.show', $record)),
    };
}

it('persists more than thirty valid updates within each module allowance', function (string $profile): void {
    $this->travelTo('2026-09-30T00:00:00Z');
    $user = User::factory()->create();
    $record = moduleRateLimitRecord($user, $profile);
    Passport::actingAs($user);

    for ($request = 1; $request <= 31; $request++) {
        moduleRateLimitAction($this, $profile, $record, 'Revision '.$request)
            ->assertOk()->assertHeader('X-RateLimit-Limit', '300');
    }

    $this->assertDatabaseHas($record->getTable(), [
        'id' => $record->getKey(), $profile === 'boards' ? 'name' : 'title' => 'Revision 31',
    ]);
})->with(['boards', 'focus', 'calendar', 'journals', 'letters', 'notes']);

it('enforces three hundred account requests per independent module and renews after one minute', function (
    string $profile,
): void {
    $this->travelTo('2026-09-30T00:00:00Z');
    $user = User::factory()->create();
    $other = User::factory()->create();
    $record = moduleRateLimitRecord($user, $profile);
    Passport::actingAs($user);
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.1']);

    for ($request = 1; $request < 300; $request++) {
        $this->getJson(moduleRateLimitReadUrl($profile))->assertOk();
    }
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.2']);
    moduleRateLimitAction($this, $profile, $record, 'Last allowed revision')
        ->assertOk()->assertHeader('X-RateLimit-Limit', '300')
        ->assertHeader('X-RateLimit-Remaining', '0');
    $before = $record->fresh()->getRawOriginal();

    moduleRateLimitAction($this, $profile, $record, 'Blocked revision')
        ->assertTooManyRequests()
        ->assertJsonPath('data', null)->assertJsonPath('status', 429)
        ->assertJsonPath('message', 'Too Many Attempts.')
        ->assertHeader('Retry-After', '60')
        ->assertHeader('X-RateLimit-Limit', '300')
        ->assertHeader('X-RateLimit-Remaining', '0')
        ->assertHeader('X-RateLimit-Reset', '1790726460');
    expect($record->fresh()->getRawOriginal())->toBe($before);
    $this->assertDatabaseEmpty('trash_entries');

    foreach (['boards', 'focus', 'calendar', 'journals', 'letters', 'notes', 'project-reads'] as $otherProfile) {
        if ($otherProfile === $profile) {
            continue;
        }
        $this->getJson(moduleRateLimitReadUrl($otherProfile))->assertOk()
            ->assertHeader('X-RateLimit-Limit', '300')
            ->assertHeader('X-RateLimit-Remaining', '299');
    }
    $this->getJson(route('auth.me'))->assertOk()
        ->assertHeader('X-RateLimit-Limit', '30')->assertHeader('X-RateLimit-Remaining', '29');
    $this->getJson(route('resource.tags'))->assertOk()
        ->assertHeader('X-RateLimit-Limit', '120')->assertHeader('X-RateLimit-Remaining', '119');
    Passport::actingAs($other);
    $this->getJson(moduleRateLimitReadUrl($profile))->assertOk()
        ->assertHeader('X-RateLimit-Limit', '300')->assertHeader('X-RateLimit-Remaining', '299');
    Passport::actingAs($user);
    $this->getJson(moduleRateLimitReadUrl($profile))->assertTooManyRequests();

    $this->travel(61)->seconds();
    moduleRateLimitAction($this, $profile, $record, 'Saved after renewal')
        ->assertOk()->assertHeader('X-RateLimit-Limit', '300')
        ->assertHeader('X-RateLimit-Remaining', '299');
    if ($profile === 'project-reads') {
        expect($record->fresh()->getRawOriginal())->toBe($before);
    } else {
        $this->assertDatabaseHas($record->getTable(), [
            'id' => $record->getKey(), $profile === 'boards' ? 'name' : 'title' => 'Saved after renewal',
        ]);
    }
})->with(['boards', 'focus', 'calendar', 'journals', 'letters', 'notes', 'project-reads']);
