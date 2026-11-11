<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiRateLimitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'api.rate_limit_per_second' => 10,
            'trustedproxy.proxies' => [],
        ]);
        $this->freezeTime();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10']);
    }

    public function test_the_eleventh_request_from_one_ip_is_rejected_with_json_and_retry_headers(): void
    {
        for ($request = 1; $request <= 10; $request++) {
            $this->get('/api/v1', ['Accept' => 'text/html'])
                ->assertOk()
                ->assertHeader('X-RateLimit-Limit', '10')
                ->assertHeader('X-RateLimit-Remaining', (string) (10 - $request));
        }

        $this->get('/api/v1', ['Accept' => 'text/html'])
            ->assertStatus(429)
            ->assertHeader('Content-Type', 'application/json')
            ->assertHeader('X-RateLimit-Limit', '10')
            ->assertHeader('X-RateLimit-Remaining', '0')
            ->assertHeader('Retry-After', '1')
            ->assertHeader('X-RateLimit-Reset', (string) now()->addSecond()->timestamp)
            ->assertExactJson(['error' => [
                'code' => 'RATE_LIMIT_EXCEEDED',
                'message' => 'Слишком много запросов. Повторите попытку позже.',
                'details' => ['retryAfter' => 1],
            ]]);
    }

    public function test_requests_are_allowed_again_after_one_second(): void
    {
        for ($request = 0; $request < 10; $request++) {
            $this->getJson('/api/v1')->assertOk();
        }

        $this->getJson('/api/v1')->assertStatus(429);

        $this->travel(1)->seconds();

        $this->getJson('/api/v1')
            ->assertOk()
            ->assertHeader('X-RateLimit-Remaining', '9')
            ->assertHeaderMissing('Retry-After');
    }

    public function test_different_ips_have_independent_budgets(): void
    {
        for ($request = 0; $request < 10; $request++) {
            $this->getJson('/api/v1')->assertOk();
        }

        $this->getJson('/api/v1')->assertStatus(429);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->getJson('/api/v1')
            ->assertOk()
            ->assertHeader('X-RateLimit-Remaining', '9');

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])
            ->getJson('/api/v1')
            ->assertStatus(429);
    }

    public function test_api_routes_and_methods_share_one_budget_including_failed_requests(): void
    {
        $schedule = '/api/v1/schedules/00000000-0000-4000-8000-000000000099';

        for ($request = 0; $request < 3; $request++) {
            $this->getJson('/api/v1')->assertOk();
            $this->getJson($schedule.'/calendar')->assertNotFound();
            $this->patchJson($schedule.'/cells', [])->assertNotFound();
        }

        $this->postJson($schedule.'/publications', [])->assertNotFound();

        foreach ([
            ['GET', '/api/v1'],
            ['GET', $schedule.'/calendar'],
            ['PATCH', $schedule.'/cells'],
            ['POST', $schedule.'/publications'],
        ] as [$method, $uri]) {
            $this->json($method, $uri)
                ->assertStatus(429)
                ->assertHeader('Retry-After', '1')
                ->assertHeader('X-RateLimit-Limit', '10')
                ->assertHeader('X-RateLimit-Remaining', '0')
                ->assertJsonPath('error.code', 'RATE_LIMIT_EXCEEDED')
                ->assertJsonPath('error.details.retryAfter', 1);
        }
    }

    public function test_untrusted_forwarded_headers_and_host_names_cannot_bypass_the_ip_limit(): void
    {
        for ($request = 1; $request <= 10; $request++) {
            $this->getJson('/api/v1', [
                'Host' => $request % 2 === 0 ? 'example.on-forge.com' : 'example.on-vapor.com',
                'X-Forwarded-For' => '203.0.113.'.$request,
                'Forwarded' => 'for=203.0.113.'.$request,
                'X-Real-IP' => '203.0.113.'.$request,
            ])->assertOk();
        }

        $this->getJson('/api/v1', [
            'Host' => 'example.on-forge.com',
            'X-Forwarded-For' => '203.0.113.99',
            'Forwarded' => 'for=203.0.113.99',
            'X-Real-IP' => '203.0.113.99',
        ])->assertStatus(429);
    }

    public function test_an_explicitly_trusted_proxy_can_forward_independent_client_ips(): void
    {
        config(['trustedproxy.proxies' => ['198.51.100.10']]);

        for ($request = 0; $request < 10; $request++) {
            $this->getJson('/api/v1', ['X-Forwarded-For' => '203.0.113.10'])->assertOk();
        }

        $this->getJson('/api/v1', ['X-Forwarded-For' => '203.0.113.10'])->assertStatus(429);
        $this->getJson('/api/v1', ['X-Forwarded-For' => '203.0.113.20'])
            ->assertOk()
            ->assertHeader('X-RateLimit-Remaining', '9');
    }

    public function test_the_health_check_remains_available_and_does_not_use_the_api_budget(): void
    {
        for ($request = 0; $request < 10; $request++) {
            $this->getJson('/up')->assertOk()->assertHeaderMissing('X-RateLimit-Limit');
            $this->getJson('/api/v1')->assertOk();
        }

        $this->getJson('/api/v1')->assertStatus(429);
        $this->getJson('/up')->assertOk()->assertHeaderMissing('X-RateLimit-Limit');
    }

    public function test_the_request_limit_can_be_configured(): void
    {
        config(['api.rate_limit_per_second' => 2]);

        $this->getJson('/api/v1')->assertOk()->assertHeader('X-RateLimit-Limit', '2');
        $this->getJson('/api/v1')->assertOk()->assertHeader('X-RateLimit-Remaining', '0');
        $this->getJson('/api/v1')->assertStatus(429)->assertHeader('Retry-After', '1');
    }

    public function test_a_non_positive_configured_limit_still_allows_one_request_per_second(): void
    {
        config(['api.rate_limit_per_second' => 0]);

        $this->getJson('/api/v1')->assertOk()->assertHeader('X-RateLimit-Limit', '1');
        $this->getJson('/api/v1')->assertStatus(429);
    }
}
