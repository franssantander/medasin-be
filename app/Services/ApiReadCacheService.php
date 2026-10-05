<?php

namespace App\Services;

use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ApiReadCacheService
{
    /**
     * @param  Closure(): JsonResponse  $resolve
     * @param  array<string, mixed>  $context
     */
    public function response(Request $request, Closure $resolve, array $context = []): JsonResponse
    {
        $seconds = (int) config($request->routeIs('plan.index')
            ? 'cache.api_reads.plan_ttl'
            : 'cache.api_reads.ttl');
        if (! config('cache.api_reads.enabled') || $seconds <= 0 || DB::transactionLevel() > 0) {
            return $resolve();
        }

        $scopes = ['plans'];
        if (! $request->routeIs('plan.index')) {
            $user = $request->user();
            if (! $user instanceof User) {
                return $resolve();
            }
            $scopes[] = 'user:'.$user->uuid;
        }

        $key = 'api-read:v1:'.hash('sha256', json_encode([
            'revisions' => $this->revisions($scopes),
            'url' => $request->url(),
            'route' => $request->route()?->getName(),
            'query' => $this->canonicalize($request->query()),
            'context' => $this->canonicalize($context),
            'locale' => app()->getLocale(),
            'date' => now()->toDateString(),
        ], JSON_THROW_ON_ERROR));
        $store = Cache::store(config('cache.api_reads.store'));
        $body = $store->get($key);
        if (is_string($body)) {
            return JsonResponse::fromJsonString($body);
        }

        $response = $resolve();
        if ($response->getStatusCode() === 200 && $response->headers->getCookies() === []) {
            $store->put($key, $response->getContent(), $seconds);
        }

        return $response;
    }

    public function invalidateUser(User $user): void
    {
        $this->invalidateScope('user:'.$user->uuid);
    }

    public function invalidateScope(string $scope): void
    {
        Cache::store('api_read_revisions')->forever('api-read:revision:'.$scope, (string) Str::uuid());
    }

    /**
     * @param  list<string>  $scopes
     * @return array<string, string>
     */
    private function revisions(array $scopes): array
    {
        $store = Cache::store('api_read_revisions');
        $keys = array_map(fn (string $scope): string => 'api-read:revision:'.$scope, $scopes);
        $revisions = $store->many($keys);

        foreach ($revisions as $key => $revision) {
            if ($revision === null) {
                $store->add($key, (string) Str::uuid(), now()->addYears(10));
                $revisions[$key] = $store->get($key);
            }
        }

        return $revisions;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function canonicalize(array $values): array
    {
        if (! array_is_list($values)) {
            ksort($values);
        }
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = $this->canonicalize($value);
            }
        }

        return $values;
    }
}
