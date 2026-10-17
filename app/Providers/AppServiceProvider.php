<?php

namespace App\Providers;

use App\Models\Project;
use App\Models\User;
use App\Services\ApiReadCacheInvalidator;
use App\Services\Auth\TokenService;
use Carbon\CarbonInterval;
use Database\Seeders\PassportClientSeeder;
use Illuminate\Cache\Events\CacheFailedOver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Events\DatabaseRefreshed;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Relation::enforceMorphMap([
            'project' => Project::class,
            'user' => User::class,
        ]);

        $invalidator = $this->app->make(ApiReadCacheInvalidator::class);
        Event::listen('eloquent.saved: *', function (string $event, array $models) use ($invalidator): void {
            $invalidator->saved($models[0]);
        });
        Event::listen('eloquent.deleting: *', function (string $event, array $models) use ($invalidator): void {
            $invalidator->deleting($models[0]);
        });
        Event::listen('eloquent.deleted: *', function (string $event, array $models) use ($invalidator): void {
            $invalidator->deleted($models[0]);
        });
        Event::listen(CacheFailedOver::class, function (CacheFailedOver $event): void {
            Log::warning('Cache store unavailable; using the next configured store.', [
                'store' => $event->storeName,
                'exception' => $event->exception::class,
            ]);
        });

        Event::listen(DatabaseRefreshed::class, function (DatabaseRefreshed $event): void {
            if ($this->app->runningUnitTests()) {
                return;
            }

            Artisan::call('db:seed', array_filter([
                '--class' => PassportClientSeeder::class,
                '--database' => $event->database,
                '--force' => true,
            ]));
        });

        TrustProxies::at(array_values(array_filter(array_map(
            'trim',
            explode(',', (string) config('app.trusted_proxies')),
        ))));

        Password::defaults(function (): Password {
            $rule = Password::min(8);

            // Breach screening calls the Have I Been Pwned range API, so only production pays for it.
            return $this->app->isProduction() ? $rule->uncompromised() : $rule;
        });

        RateLimiter::for('auth', function (Request $request): array|Limit {
            $user = $request->user('api');
            $submitted = $request->input('username') ?? $request->input('email');
            $identity = match (true) {
                $user !== null => 'user:'.$user->getAuthIdentifier(),
                is_string($submitted) && trim($submitted) !== '' => 'login:'.hash('sha256', Str::lower(trim($submitted))),
                default => null,
            };

            if ($identity === null) {
                return Limit::none();
            }

            // Keyed by the targeted account so rotating IP addresses cannot multiply password or code guesses.
            return [
                Limit::perMinute(10)->by('minute:'.$identity),
                Limit::perHour(30)->by('hour:'.$identity),
            ];
        });

        RateLimiter::for('api', function (Request $request): Limit {
            $user = $request->user('api');

            return Limit::perMinute(30)->by(
                $user ? 'user:'.$user->getAuthIdentifier() : 'ip:'.$request->ip(),
            );
        });

        RateLimiter::for('resources', function (Request $request): Limit {
            $user = $request->user('api');

            return Limit::perMinute(120)->by(
                $user ? 'user:'.$user->getAuthIdentifier() : 'ip:'.$request->ip(),
            );
        });

        foreach (['boards', 'focus', 'calendar', 'journals', 'letters', 'notes', 'project-reads'] as $limiterName) {
            RateLimiter::for($limiterName, function (Request $request): Limit {
                $user = $request->user('api');

                return Limit::perMinute(300)->by(
                    $user ? 'user:'.$user->getAuthIdentifier() : 'ip:'.$request->ip(),
                );
            });
        }

        Passport::tokensExpireIn(CarbonInterval::minutes(TokenService::ACCESS_TOKEN_MINUTES));
        Passport::refreshTokensExpireIn(CarbonInterval::days(30));
        Passport::personalAccessTokensExpireIn(CarbonInterval::months(6));
    }
}
