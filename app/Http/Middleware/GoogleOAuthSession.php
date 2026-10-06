<?php

namespace App\Http\Middleware;

use App\Services\Auth\GoogleAuthException;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Symfony\Component\HttpFoundation\Response;

class GoogleOAuthSession
{
    public function __construct(
        private readonly EncryptCookies $cookies,
        private readonly StartSession $sessions,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $original = [
            'session.same_site' => config('session.same_site'),
            'session.http_only' => config('session.http_only'),
            'session.secure' => config('session.secure'),
            'session.block_store' => config('session.block_store'),
        ];

        config([
            'session.same_site' => 'lax',
            'session.http_only' => true,
            'session.secure' => app()->isProduction() || (bool) config('session.secure'),
            'session.block_store' => 'database',
        ]);
        $this->cookies->disableFor(['auth_token', 'refresh_token']);

        try {
            $response = $this->cookies->handle(
                $request,
                fn (Request $request): Response => $this->sessions->handle($request, $next),
            );
        } catch (LockTimeoutException) {
            $response = (new GoogleAuthException(
                'GOOGLE_OAUTH_BUSY', 'Another Google sign-in request is still processing. Please try again.', 409,
            ))->toResponse();
        } finally {
            config($original);
        }

        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
