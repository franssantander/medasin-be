<?php

namespace Tests\Feature\Calendar;

use App\Events\CalendarPlanReminderDelivered;
use App\Jobs\Calendar\DeliverCalendarPlanReminder;
use App\Models\CalendarPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Passport\Passport;
use Tests\TestCase;

class CalendarReminderBroadcastTest extends TestCase
{
    use DatabaseMigrations;

    public function test_new_reminder_broadcasts_once_after_commit(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 24)->setTime(0, 0));
        $user = User::factory()->create();
        $plan = CalendarPlan::factory()->for($user)->create([
            'reminder_offset_minutes' => 180,
            'remind_at' => now()->subMinute(),
            'reminder_token' => 'a22c2822-352d-4577-950f-70e5b88f27ca',
        ]);
        Event::fake([CalendarPlanReminderDelivered::class]);

        $delivery = new DeliverCalendarPlanReminder($plan->getKey(), $plan->reminder_token);
        DB::transaction(function () use ($delivery): void {
            $delivery->handle();
            Event::assertNotDispatched(CalendarPlanReminderDelivered::class);
        });
        $delivery->handle();

        Event::assertDispatched(
            CalendarPlanReminderDelivered::class,
            fn (CalendarPlanReminderDelivered $event): bool => $event->userId === $user->getKey()
                && $event->notificationId === $plan->reminder_token
                && $event->broadcastAs() === 'calendar.plan-reminder.delivered'
                && $event->broadcastOn()[0]->name === 'private-users.'.$user->getKey().'.notifications'
                && $event->broadcastWith() === ['notification_id' => $plan->reminder_token],
        );
        Event::assertDispatchedTimes(CalendarPlanReminderDelivered::class, 1);
    }

    public function test_stale_reminder_does_not_broadcast(): void
    {
        $user = User::factory()->create();
        $plan = CalendarPlan::factory()->for($user)->create([
            'reminder_offset_minutes' => 0,
            'remind_at' => now()->subMinute(),
            'reminder_token' => 'a22c2822-352d-4577-950f-70e5b88f27ca',
        ]);
        Event::fake([CalendarPlanReminderDelivered::class]);

        (new DeliverCalendarPlanReminder(
            $plan->getKey(),
            'f137851b-bf11-4e20-92f3-259436070686',
        ))->handle();

        Event::assertNotDispatched(CalendarPlanReminderDelivered::class);
    }

    public function test_only_owner_can_authorize_notification_channel(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        config()->set('broadcasting.default', 'reverb');
        config()->set('broadcasting.connections.reverb.key', 'test-key');
        config()->set('broadcasting.connections.reverb.secret', 'test-secret');
        config()->set('broadcasting.connections.reverb.app_id', 'test-app');
        require base_path('routes/channels.php');
        $payload = [
            'socket_id' => '123.456',
            'channel_name' => 'private-users.'.$owner->getKey().'.notifications',
        ];

        $this->postJson('/api/v1/broadcasting/auth', $payload)->assertUnauthorized();

        Passport::actingAs($other);
        $this->postJson('/api/v1/broadcasting/auth', $payload)->assertForbidden();

        Passport::actingAs($owner);
        $this->postJson('/api/v1/broadcasting/auth', $payload)
            ->assertOk()
            ->assertJsonStructure(['auth']);
    }
}
