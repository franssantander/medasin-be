<?php

namespace App\Providers;

use App\Models\Project;
use App\Models\User;
use App\Services\ApiReadCacheInvalidator;
use Carbon\CarbonInterval;
use Illuminate\Cache\Events\CacheFailedOver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
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

        Passport::tokensExpireIn(CarbonInterval::days(15));
        Passport::refreshTokensExpireIn(CarbonInterval::days(30));
        Passport::personalAccessTokensExpireIn(CarbonInterval::months(6));
    }
}
