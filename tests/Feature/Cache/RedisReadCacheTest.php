<?php

namespace Tests\Feature\Cache;

use App\Models\User;
use Illuminate\Cache\Events\CacheFailedOver;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Tests\TestCase;

class RedisReadCacheTest extends TestCase
{
    use DatabaseMigrations;

    public function test_real_redis_caches_json_and_recovers_from_an_outage_without_stale_data(): void
    {
        if (getenv('RUN_REDIS_CACHE_TESTS') !== '1') {
            $this->markTestSkipped('Set RUN_REDIS_CACHE_TESTS=1 to exercise the Docker Redis service.');
        }
        $this->assertTrue(extension_loaded('redis'), 'The PHP runtime must have PhpRedis installed.');
        config([
            'cache.prefix' => 'api-read-integration:'.Str::uuid().':',
            'cache.api_reads.store' => 'api_reads',
            'cache.api_reads.ttl' => 60,
        ]);
        Cache::forgetDriver(['redis', 'database', 'api_reads', 'api_read_revisions']);
        $keys = [];
        $failures = [];
        Event::listen(KeyWritten::class, function (KeyWritten $event) use (&$keys): void {
            if ($event->storeName === 'redis' && str_starts_with($event->key, 'api-read:v1:')) {
                $keys[] = $event->key;
                $this->assertIsString($event->value);
                $this->assertSame(60, $event->seconds);
            }
        });
        Event::listen(CacheFailedOver::class, function (CacheFailedOver $event) use (&$failures): void {
            $failures[] = $event->storeName;
        });
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'Before outage']);
        Passport::actingAs($user);

        try {
            $first = $this->getJson(route('project.index'))->assertOk()->assertJsonPath('data.0.name', 'Before outage');
            $this->assertCount(1, $keys);
            $oldKey = $keys[0];
            $this->assertSame($first->getContent(), Cache::store('redis')->get($oldKey));
            DB::enableQueryLog();
            $this->getJson(route('project.index'))->assertJsonPath('data.0.name', 'Before outage');
            $this->assertSame([], array_values(array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains($query['query'], 'from "projects"'))));

            config([
                'database.redis.api_read_test_down' => array_replace(config('database.redis.cache'), [
                    'url' => null,
                    'host' => '127.0.0.1',
                    'port' => 1,
                    'timeout' => 0.1,
                    'read_timeout' => 0.1,
                    'max_retries' => 0,
                ]),
                'cache.stores.redis.connection' => 'api_read_test_down',
            ]);
            Cache::forgetDriver(['redis', 'api_reads']);
            $this->putJson(route('project.update', $project), ['name' => 'After outage'])->assertOk();
            $this->getJson(route('project.index'))->assertJsonPath('data.0.name', 'After outage');
            $this->assertContains('redis', $failures);
            $this->assertNotEmpty(DB::table('cache')->where('key', 'like', config('cache.prefix').'api-read:v1:%')->get());

            config(['cache.stores.redis.connection' => 'cache']);
            Cache::forgetDriver(['redis', 'api_reads']);

            $this->getJson(route('project.index'))->assertJsonPath('data.0.name', 'After outage');
            $this->assertSame($first->getContent(), Cache::store('redis')->get($oldKey));
            $this->assertCount(2, $keys);
        } finally {
            config(['cache.stores.redis.connection' => 'cache']);
            Cache::forgetDriver(['redis', 'api_reads']);
            foreach (array_unique($keys) as $key) {
                Cache::store('redis')->forget($key);
            }
        }
    }
}
