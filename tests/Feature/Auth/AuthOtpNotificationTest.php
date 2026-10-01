<?php

namespace Tests\Feature\Auth;

use App\Enum\AuthOtpPurpose;
use App\Models\AuthOtp;
use App\Models\User;
use App\Notifications\AuthOtpNotification;
use Illuminate\Mail\SentMessage;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;

class AuthOtpNotificationTest extends AuthTestCase
{
    public function test_registration_queues_an_encrypted_otp_after_commit_and_delivers_it_through_mail(): void
    {
        config(['queue.default' => 'database']);
        Event::fake([NotificationSent::class]);

        DB::transaction(function (): void {
            $this->postJson(route('auth.register'), $this->registrationPayload())->assertCreated();

            $this->assertDatabaseCount('jobs', 0);
        });

        $this->assertDatabaseCount('jobs', 1);
        $job = Queue::connection('database')->pop();
        $payload = $job->payload();
        $queued = unserialize(Crypt::decrypt($payload['data']['command']));
        $this->assertInstanceOf(SendQueuedNotifications::class, $queued);
        $this->assertInstanceOf(AuthOtpNotification::class, $queued->notification);
        $this->assertStringNotContainsString($queued->notification->code, $payload['data']['command']);

        $job->fire();

        Event::assertDispatched(
            NotificationSent::class,
            fn (NotificationSent $event): bool => $event->channel === 'mail'
                && $event->notifiable->email === 'ada@example.com'
                && $event->notification instanceof AuthOtpNotification
                && $event->notification->purpose === AuthOtpPurpose::EMAIL_VERIFICATION,
        );
    }

    public function test_superseded_queued_notifications_are_not_sent(): void
    {
        $this->freezeTime();
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $this->postJson(route('auth.resend-verification'), ['email' => $user->email])->assertAccepted();
        $old = $this->latestOtp($user, AuthOtpPurpose::EMAIL_VERIFICATION);
        $this->assertTrue($old->shouldSend($user, 'mail'));
        $this->travel(60)->seconds();

        $this->postJson(route('auth.resend-verification'), ['email' => $user->email])->assertAccepted();

        $current = $this->latestOtp($user, AuthOtpPurpose::EMAIL_VERIFICATION);
        $this->assertFalse($old->shouldSend($user, 'mail'));
        $this->assertTrue($current->shouldSend($user, 'mail'));
    }

    #[DataProvider('invalidChallengeStates')]
    public function test_skips_queued_notifications_for_inactive_challenges(array $changes): void
    {
        $this->travelTo('2026-09-30T00:00:00+00:00');
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $this->postJson(route('auth.resend-verification'), ['email' => $user->email])->assertAccepted();
        $notification = $this->latestOtp($user, AuthOtpPurpose::EMAIL_VERIFICATION);

        AuthOtp::where('user_id', $user->id)->update($changes);

        $this->assertFalse($notification->shouldSend($user, 'mail'));
    }

    /** @return array<string, array{array<string, int|string>}> */
    public static function invalidChallengeStates(): array
    {
        return [
            'expired' => [['expires_at' => '2026-09-30 00:00:00']],
            'consumed' => [['consumed_at' => '2026-09-30 00:00:00']],
            'exhausted attempts' => [['failed_attempts' => 5]],
        ];
    }

    public function test_does_not_send_a_code_to_another_account_or_notification_channel(): void
    {
        Notification::fake();
        $owner = User::factory()->unverified()->create();
        $other = User::factory()->create();
        $this->postJson(route('auth.resend-verification'), ['email' => $owner->email])->assertAccepted();
        $notification = $this->latestOtp($owner, AuthOtpPurpose::EMAIL_VERIFICATION);

        $this->assertFalse($notification->shouldSend($other, 'mail'));
        $this->assertFalse($notification->shouldSend($owner, 'database'));
    }

    public function test_verification_email_delivers_medasin_html_and_plain_text_with_the_same_code(): void
    {
        config(['app.name' => 'Laravel', 'mail.default' => 'array']);
        $user = User::factory()->make();
        $notification = new AuthOtpNotification(1, 'challenge-version', '012345', AuthOtpPurpose::EMAIL_VERIFICATION);

        $sent = app(MailChannel::class)->send($user, $notification);

        $this->assertInstanceOf(SentMessage::class, $sent);
        $message = $sent->getOriginalMessage();

        foreach ([$message->getHtmlBody(), $message->getTextBody()] as $content) {
            $this->assertIsString($content);
            $this->assertStringContainsString('Medasin', $content);
            $this->assertStringNotContainsString('Laravel', $content);
            $this->assertStringContainsString('012345', $content);
            $this->assertStringContainsString('verify your email address', $content);
            $this->assertStringContainsString('60 minutes after it was requested', $content);
            $this->assertStringContainsString('only be used once', $content);
            $this->assertStringContainsString('Keep this code private.', $content);
            $this->assertStringContainsString('If you did not request this code, you can ignore this email.', $content);
        }

        $this->assertCount(1, $message->getAttachments());
        $logo = $message->getAttachments()[0];
        $this->assertSame('inline', $logo->getDisposition());
        $this->assertSame('image/png', $logo->getContentType());
        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $logo->getBody());
        $this->assertStringContainsString('src="cid:'.$logo->getContentId().'"', $message->getHtmlBody());

        $this->assertStringNotContainsString('<', $message->getTextBody());
    }

    #[DataProvider('notificationPurposes')]
    public function test_email_explains_the_code_purpose_and_expiry(
        AuthOtpPurpose $purpose,
        string $subject,
        string $instruction,
        int $expiresInMinutes,
    ): void {
        $user = User::factory()->make();
        $notification = new AuthOtpNotification(1, 'challenge-version', '012345', $purpose);

        $mail = $notification->toMail($user);
        $content = $mail->render();

        $this->assertSame($subject, $mail->subject);
        $this->assertStringContainsString('012345', $content);
        $this->assertStringContainsString($instruction, $content);
        $this->assertStringContainsString($expiresInMinutes.' minutes', $content);
        $this->assertStringContainsString('only be used once', $content);
    }

    /** @return array<string, array{AuthOtpPurpose, string, string, int}> */
    public static function notificationPurposes(): array
    {
        return [
            'email verification' => [
                AuthOtpPurpose::EMAIL_VERIFICATION,
                'Verify your email address',
                'verify your email address',
                60,
            ],
            'password recovery' => [
                AuthOtpPurpose::PASSWORD_RESET,
                'Reset your password',
                'verify your password reset request',
                10,
            ],
        ];
    }
}
