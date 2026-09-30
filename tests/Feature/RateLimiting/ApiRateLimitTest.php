<?php

namespace Tests\Feature\RateLimiting;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class ApiRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_429_after_thirty_authenticated_requests_with_retry_headers(): void
    {
        $this->travelTo('2026-09-30T00:00:00+00:00');
        Passport::actingAs(User::factory()->create());

        $this->getJson(route('auth.me'))->assertOk()
            ->assertHeader('X-RateLimit-Limit', '30')
            ->assertHeader('X-RateLimit-Remaining', '29')
            ->assertHeaderMissing('Retry-After');
        for ($request = 2; $request <= 30; $request++) {
            $this->getJson(route('auth.me'))->assertOk();
        }

        $this->getJson(route('auth.me'))->assertTooManyRequests()
            ->assertJsonPath('data', null)
            ->assertJsonPath('status', 429)
            ->assertJsonPath('message', 'Too Many Attempts.')
            ->assertHeader('Retry-After', '60')
            ->assertHeader('X-RateLimit-Limit', '30')
            ->assertHeader('X-RateLimit-Remaining', '0')
            ->assertHeader('X-RateLimit-Reset', '1790726460');
    }

    public function test_guest_allowance_returns_429_after_thirty_requests_and_renews_after_one_minute(): void
    {
        $this->freezeTime();

        for ($request = 1; $request <= 30; $request++) {
            $this->getJson(route('plan.index'))->assertOk()
                ->assertHeader('X-RateLimit-Limit', '30')
                ->assertHeader('X-RateLimit-Remaining', (string) (30 - $request));
        }
        $this->getJson(route('plan.index'))->assertTooManyRequests();

        $this->travel(61)->seconds();

        $this->getJson(route('plan.index'))->assertOk()
            ->assertHeader('X-RateLimit-Limit', '30')
            ->assertHeader('X-RateLimit-Remaining', '29');
    }

    public function test_authenticated_account_shares_its_allowance_between_protected_and_public_endpoints(): void
    {
        $this->freezeTime();
        Passport::actingAs(User::factory()->create());

        for ($request = 1; $request < 30; $request++) {
            $this->getJson(route('auth.me'))->assertOk();
        }
        $this->getJson(route('plan.index'))->assertOk()
            ->assertHeader('X-RateLimit-Remaining', '0');

        $this->getJson(route('auth.me'))->assertTooManyRequests();
    }

    public function test_guest_shares_its_allowance_between_public_endpoints_and_login_validation_failures(): void
    {
        $this->freezeTime();

        for ($request = 1; $request <= 15; $request++) {
            $this->getJson(route('plan.index'))->assertOk();
            $this->postJson(route('auth.login'))->assertUnprocessable()
                ->assertJsonValidationErrors(['username', 'password']);
        }

        $this->getJson(route('plan.index'))->assertTooManyRequests();
        $this->postJson(route('auth.login'))->assertTooManyRequests();
    }

    public function test_accounts_using_the_same_ip_have_separate_allowances(): void
    {
        $this->freezeTime();
        $first = User::factory()->create();
        $second = User::factory()->create();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.1']);
        Passport::actingAs($first);

        for ($request = 1; $request <= 30; $request++) {
            $this->getJson(route('auth.me'))->assertOk();
        }
        $this->getJson(route('auth.me'))->assertTooManyRequests();

        Passport::actingAs($second);

        $this->getJson(route('auth.me'))->assertOk()
            ->assertHeader('X-RateLimit-Remaining', '29');
    }

    public function test_account_keeps_its_allowance_when_its_ip_changes(): void
    {
        $this->freezeTime();
        Passport::actingAs(User::factory()->create());
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.1']);

        for ($request = 1; $request < 30; $request++) {
            $this->getJson(route('auth.me'))->assertOk();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.2']);
        $this->getJson(route('auth.me'))->assertOk()
            ->assertHeader('X-RateLimit-Remaining', '0');

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.3'])
            ->getJson(route('auth.me'))->assertTooManyRequests();
    }

    public function test_guest_ips_have_separate_allowances(): void
    {
        $this->freezeTime();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.1']);

        for ($request = 1; $request <= 30; $request++) {
            $this->getJson(route('plan.index'))->assertOk();
        }
        $this->getJson(route('plan.index'))->assertTooManyRequests();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.2'])
            ->getJson(route('plan.index'))->assertOk()
            ->assertHeader('X-RateLimit-Remaining', '29');
    }
}
