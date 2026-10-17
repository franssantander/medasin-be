<?php

namespace App\Http\Middleware;

use App\Support\ApiResponder;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CSRF defence for cookie-authenticated API writes. SameSite=Strict cookies already stop other sites,
 * but not sibling subdomains of the same site, so an unsafe request that carries the auth cookies and
 * a browser Origin header must come from the frontend (or the API's own) origin. Requests without an
 * Origin header (non-browser clients) and bearer-token clients are unaffected.
 */
class RejectCrossOriginCookieWrites
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->headers->get('Origin');
        $usesAuthCookies = $request->cookies->has('auth_token') || $request->cookies->has('refresh_token');
        $trusted = $this->trustedOrigins();

        if ($origin !== null && $usesAuthCookies && $trusted !== [] && ! $request->isMethodSafe()
            && ! in_array($this->origin($origin), $trusted, true)) {
            return ApiResponder::error(null, 'This request did not come from the Medasin app.', Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }

    /**
     * The frontend origin is FRONTEND_URL; production falls back to the Google sign-in return URI's origin.
     * With neither configured (local development) the check is skipped.
     *
     * @return list<string>
     */
    private function trustedOrigins(): array
    {
        $frontend = config('app.frontend_url')
            ?: (app()->isProduction() ? config('services.google.frontend_redirect') : null);

        if (! is_string($frontend) || $frontend === '') {
            return [];
        }

        return array_values(array_unique(array_filter([
            $this->origin($frontend),
            $this->origin((string) config('app.url')),
        ])));
    }

    private function origin(string $url): ?string
    {
        $parts = parse_url($url);
        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return strtolower($parts['scheme'].'://'.$parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
