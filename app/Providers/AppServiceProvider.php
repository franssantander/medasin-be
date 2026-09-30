<?php

namespace App\Providers;

use App\Models\Project;
use App\Models\User;
use Carbon\CarbonInterval;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
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

        RateLimiter::for('api', function (Request $request): Limit {
            $user = $request->user('api');

            return Limit::perMinute(30)->by(
                $user ? 'user:'.$user->getAuthIdentifier() : 'ip:'.$request->ip(),
            );
        });

        Passport::tokensExpireIn(CarbonInterval::days(15));
        Passport::refreshTokensExpireIn(CarbonInterval::days(30));
        Passport::personalAccessTokensExpireIn(CarbonInterval::months(6));
    }
}
