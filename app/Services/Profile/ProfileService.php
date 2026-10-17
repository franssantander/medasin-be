<?php

namespace App\Services\Profile;

use App\Enum\AuthOtpPurpose;
use App\Models\Area;
use App\Models\AuthOtp;
use App\Models\Letter;
use App\Models\LetterMedia;
use App\Models\Note;
use App\Models\NoteMedia;
use App\Models\RefreshToken;
use App\Models\Resource;
use App\Models\ResourceAttachment;
use App\Models\User;
use App\Notifications\AccountDeletionNotification;
use App\Services\Auth\AuthOtpService;
use App\Services\Auth\TokenService;
use App\Support\ImageMetadataStripper;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\AccessToken;
use Laravel\Passport\Passport;
use RuntimeException;
use Symfony\Component\HttpFoundation\Cookie;
use Throwable;

class ProfileService
{
    public function __construct(
        private readonly TokenService $tokens,
        private readonly FileCleanupService $files,
        private readonly AuthOtpService $otp,
        private readonly ImageMetadataStripper $metadata,
    ) {}

    public function storeImage(User $user, UploadedFile $image): User
    {
        return DB::transaction(function () use ($user, $image): User {
            $user = User::query()->lockForUpdate()->findOrFail($user->getKey());
            $previous = $user->profile_image_path;
            $this->metadata->strip($image->getRealPath());
            $directory = "profiles/{$user->uuid}";
            $filename = $image->hashName();
            $path = "{$directory}/{$filename}";

            DB::afterRollBack(function () use ($path): void {
                try {
                    $this->files->deleteOrQueue(['public' => [$path]]);
                } catch (Throwable $exception) {
                    report($exception);
                }
            });

            if ($image->storeAs($directory, $filename, 'public') === false) {
                throw new RuntimeException('Unable to store the profile image.');
            }

            $user->forceFill(['profile_image_path' => $path])->save();

            if ($previous) {
                $this->files->enqueue(['public' => [$previous]]);
            }

            return $user->refresh();
        });
    }

    public function removeImage(User $user): User
    {
        return DB::transaction(function () use ($user): User {
            $user = User::query()->lockForUpdate()->findOrFail($user->getKey());
            $previous = $user->profile_image_path;

            if ($previous) {
                $this->files->enqueue(['public' => [$previous]]);
                $user->forceFill(['profile_image_path' => null])->save();
            }

            return $user->refresh();
        });
    }

    /**
     * @param  array{current_password: string, password: string, password_confirmation: string}  $attributes
     * @return array{0: Cookie, 1: Cookie}
     */
    public function changePassword(User $user, #[\SensitiveParameter] array $attributes): array
    {
        $currentToken = $user->token();
        $accessTokenId = match (true) {
            $currentToken instanceof AccessToken => (string) $currentToken->oauth_access_token_id,
            $currentToken instanceof Model => (string) $currentToken->getKey(),
            default => null,
        };

        return DB::transaction(function () use ($user, $attributes, $accessTokenId): array {
            $user = User::query()->lockForUpdate()->findOrFail($user->getKey());
            $this->checkPassword($user, $attributes['current_password']);
            $rememberMe = $accessTokenId !== null && (bool) RefreshToken::query()
                ->where('user_id', $user->getKey())
                ->where('access_token_id', $accessTokenId)
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->value('remember_me');

            $user->forceFill([
                'password' => Hash::make($attributes['password']),
                'remember_token' => Str::random(60),
            ])->save();
            $this->tokens->revokeAllForUser($user);
            Password::broker()->deleteToken($user);
            AuthOtp::query()->where('user_id', $user->getKey())
                ->where('purpose', AuthOtpPurpose::PASSWORD_RESET)->delete();
            DB::table('sessions')->where('user_id', $user->getKey())->delete();
            DB::table('personal_access_tokens')->where('tokenable_type', $user->getMorphClass())
                ->where('tokenable_id', $user->getKey())->delete();
            event(new PasswordReset($user));

            return $this->tokens->issue($user, $rememberMe);
        });
    }

    /**
     * Emails a one-time code that confirms deletion for accounts without a known password (Google sign-ups).
     *
     * @return array{sent: bool, otp_expires_in: int, resend_after: int}
     */
    public function sendDeletionCode(User $user): array
    {
        $sent = $this->otp->send($user, AuthOtpPurpose::ACCOUNT_DELETION);

        return ['sent' => $sent, ...$this->otp->status($user, AuthOtpPurpose::ACCOUNT_DELETION)];
    }

    /**
     * Schedules the account for permanent deletion after the grace period, once the owner has proved
     * the account with its password or an emailed code. Every session is signed out straight away;
     * signing in again before the purge cancels it (TokenService::issue).
     */
    public function scheduleDeletion(User $user, #[\SensitiveParameter] ?string $password, #[\SensitiveParameter] ?string $otp): CarbonImmutable
    {
        // The code is consumed in its own transaction so a wrong guess still counts after the validation error.
        if ($password !== null && $password !== '') {
            $this->checkPassword($user, $password);
        } elseif (! $this->otp->consume($user, AuthOtpPurpose::ACCOUNT_DELETION, (string) $otp)) {
            throw ValidationException::withMessages([
                'otp' => ['The verification code is invalid or has expired.'],
            ]);
        }

        return DB::transaction(function () use ($user): CarbonImmutable {
            $user = User::query()->lockForUpdate()->findOrFail($user->getKey());
            $deletesAt = CarbonImmutable::now()->addDays((int) config('auth.account_deletion_grace_days'))->startOfSecond();

            $user->forceFill(['deletion_scheduled_at' => $deletesAt])->saveOrFail();
            $this->tokens->revokeAllForUser($user);
            Password::broker()->deleteToken($user);
            DB::table('sessions')->where('user_id', $user->getKey())->delete();
            $user->notify(new AccountDeletionNotification(AccountDeletionNotification::SCHEDULED, $deletesAt));

            return $deletesAt;
        });
    }

    /**
     * Permanently deletes an account whose grace period has ended. Returns false when the owner signed
     * in again (cancelling the deletion) or the account is already gone.
     */
    public function purgeScheduledDeletion(User $user): bool
    {
        $email = null;
        $purged = DB::transaction(function () use ($user, &$email): bool {
            $user = User::query()->lockForUpdate()->find($user->getKey());
            if (! $user || $user->deletion_scheduled_at?->lte(now()) !== true) {
                return false;
            }

            $email = $user->email;
            $this->purge($user);

            return true;
        });

        if ($purged) {
            Notification::route('mail', $email)->notify(new AccountDeletionNotification(AccountDeletionNotification::COMPLETED));
        }

        return $purged;
    }

    /** Removes the locked account with its tokens, sessions, notifications, and files. */
    protected function purge(User $user): void
    {
        [$files, $directories] = $this->fileManifest($user);
        $this->files->enqueue($files, $directories);

        $this->tokens->revokeAllForUser($user);
        Password::broker()->deleteToken($user);
        $user->notifications()->delete();
        DB::table('sessions')->where('user_id', $user->getKey())->delete();
        DB::table('personal_access_tokens')->where('tokenable_type', $user->getMorphClass())
            ->where('tokenable_id', $user->getKey())->delete();

        $clientIds = $user->oauthApps()->pluck('id');
        $tokenIds = Passport::token()->newQuery()->where('user_id', $user->getKey())
            ->orWhereIn('client_id', $clientIds)->pluck('id');
        Passport::refreshToken()->newQuery()->whereIn('access_token_id', $tokenIds)->delete();
        Passport::token()->newQuery()->whereIn('id', $tokenIds)->delete();
        Passport::authCode()->newQuery()->where('user_id', $user->getKey())
            ->orWhereIn('client_id', $clientIds)->delete();
        Passport::deviceCode()->newQuery()->where('user_id', $user->getKey())
            ->orWhereIn('client_id', $clientIds)->delete();
        $user->oauthApps()->delete();
        $user->delete();
    }

    protected function checkPassword(User $user, #[\SensitiveParameter] string $password): void
    {
        if (! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }
    }

    /** @return array{array<string, list<string>>, array<string, list<string>>} */
    protected function fileManifest(User $user): array
    {
        // toBase(): an empty Eloquent collection stays Eloquent after pluck/map and cannot merge plain paths.
        $areas = Area::withTrashed()->where('user_id', $user->getKey())->get(['id', 'uuid', 'background_image'])->toBase();
        $resources = Resource::withTrashed()->where('user_id', $user->getKey())->get(['id', 'uuid'])->toBase();
        $noteIds = Note::withTrashed()->where('user_id', $user->getKey())->pluck('id');
        $letterIds = Letter::withTrashed()->where('user_id', $user->getKey())->pluck('id');

        $mediaFiles = NoteMedia::query()->whereIn('note_id', $noteIds)->pluck('path')
            ->merge(LetterMedia::query()->whereIn('letter_id', $letterIds)->pluck('path'))
            ->filter()->unique()->values()->all();
        // Media uploaded before it became private may still sit on the public disk, so it is purged from both.
        $publicFiles = $areas->pluck('background_image')
            ->merge($mediaFiles)
            ->push($user->profile_image_path)->filter()->unique()->values()->all();
        $localFiles = ResourceAttachment::withTrashed()->whereIn('resource_id', $resources->pluck('id'))
            ->whereNotNull('path')->pluck('path')->merge($mediaFiles)->unique()->values()->all();
        $mediaDirectories = $areas->map(fn (Area $area): string => "areas/{$area->uuid}/notes")
            ->merge(["notes/{$user->uuid}", "letters/{$user->uuid}"]);

        return [
            ['public' => $publicFiles, 'local' => $localFiles],
            [
                'public' => $mediaDirectories->merge(["profiles/{$user->uuid}"])->all(),
                'local' => $resources->map(fn (Resource $resource): string => "resources/{$resource->uuid}")
                    ->merge($mediaDirectories)->all(),
            ],
        ];
    }
}
