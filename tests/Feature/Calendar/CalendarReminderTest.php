<?php

namespace Tests\Feature\Calendar;

use App\Jobs\Calendar\DeliverCalendarPlanReminder;
use App\Jobs\Calendar\SendCalendarPlanReminderEmail;
use App\Models\CalendarPlan;
use App\Models\User;
use App\Notifications\CalendarPlanReminder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Passport\Passport;
use RuntimeException;
use Tests\TestCase;

class CalendarReminderTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatcher_queues_due_reminders_only(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 24)->setTime(0, 0));
        $user = User::factory()->create();
        $due = CalendarPlan::factory()->for($user)->create([
            'reminder_offset_minutes' => 0,
            'remind_at' => now()->subMinute(),
            'reminder_token' => 'a22c2822-352d-4577-950f-70e5b88f27ca',
        ]);
        CalendarPlan::factory()->for($user)->create([
            'reminder_offset_minutes' => 0,
            'remind_at' => now()->addHour(),
            'reminder_token' => '0b29e9bf-4c6b-4f48-bc19-580b0702530b',
        ]);
        Queue::fake([DeliverCalendarPlanReminder::class]);

        $this->artisan('calendar:dispatch-reminders')->assertSuccessful();

        Queue::assertPushed(DeliverCalendarPlanReminder::class, fn ($job) => $job->planId === $due->getKey());
        Queue::assertPushed(DeliverCalendarPlanReminder::class, 1);
    }

    public function test_delivery_creates_one_in_app_notice_and_email_is_retryable(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 24)->setTime(0, 0));
        $user = User::factory()->create();
        $plan = CalendarPlan::factory()->for($user)->create([
            'reminder_offset_minutes' => 180,
            'remind_at' => now()->subMinute(),
            'reminder_token' => 'a22c2822-352d-4577-950f-70e5b88f27ca',
        ]);

        $delivery = new DeliverCalendarPlanReminder($plan->getKey(), $plan->reminder_token);
        $delivery->handle();
        $delivery->handle();
        $this->assertDatabaseCount('notifications', 1);
        $this->assertNotNull($plan->fresh()->notified_at);

        Queue::fake([SendCalendarPlanReminderEmail::class]);
        $this->artisan('calendar:dispatch-reminders')->assertSuccessful();
        Queue::assertPushed(SendCalendarPlanReminderEmail::class, fn ($job) => $job->planId === $plan->getKey());

        Notification::shouldReceive('sendNow')->once()->andThrow(new RuntimeException('Mail unavailable'));
        try {
            (new SendCalendarPlanReminderEmail($plan->getKey(), $plan->reminder_token))->handle();
            $this->fail('Expected mail delivery to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Mail unavailable', $exception->getMessage());
        }
        $this->assertNull($plan->fresh()->email_sent_at);
        $this->assertDatabaseCount('notifications', 1);

        Notification::fake();
        (new SendCalendarPlanReminderEmail($plan->getKey(), $plan->reminder_token))->handle();
        Notification::assertSentTo($user, CalendarPlanReminder::class);
        $this->assertNotNull($plan->fresh()->email_sent_at);
    }

    public function test_stale_or_deleted_reminders_do_not_fire(): void
    {
        $user = User::factory()->create();
        $plan = CalendarPlan::factory()->for($user)->create([
            'reminder_offset_minutes' => 0,
            'remind_at' => now()->subMinute(),
            'reminder_token' => 'a22c2822-352d-4577-950f-70e5b88f27ca',
        ]);
        (new DeliverCalendarPlanReminder($plan->getKey(), 'f137851b-bf11-4e20-92f3-259436070686'))->handle();
        $plan->delete();
        (new DeliverCalendarPlanReminder($plan->getKey(), $plan->reminder_token))->handle();
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_notification_api_is_private_and_marks_owned_notice_read(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $notice = $owner->notifications()->create([
            'id' => 'a22c2822-352d-4577-950f-70e5b88f27ca',
            'type' => CalendarPlanReminder::class,
            'data' => ['title' => 'Appointment'],
        ]);

        Passport::actingAs($other);
        $this->getJson(route('notifications.index'))->assertOk()->assertJsonPath('data.total', 0);
        $this->patchJson(route('notifications.read', $notice->getKey()))->assertNotFound();

        Passport::actingAs($owner);
        $this->getJson(route('notifications.index', ['unread_only' => 1]))
            ->assertOk()->assertJsonPath('data.data.0.id', $notice->getKey());
        $this->patchJson(route('notifications.read', $notice->getKey()))
            ->assertOk()->assertJsonPath('data.id', $notice->getKey());
        $this->getJson(route('notifications.index', ['unread_only' => 1]))
            ->assertOk()->assertJsonPath('data.total', 0);
    }
}
