<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiCorsTest extends TestCase
{
    private const FRONTEND = 'https://front.example.test';

    private const SECOND_FRONTEND = 'https://preview.example.test';

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();
        config([
            'cors.allowed_origins' => [self::FRONTEND, self::SECOND_FRONTEND],
            'api.rate_limit_per_second' => 10,
        ]);
    }

    public function test_allowed_frontends_receive_their_exact_origin_and_a_vary_header(): void
    {
        foreach ([self::FRONTEND, self::SECOND_FRONTEND] as $origin) {
            $this->getJson('/api/v1', ['Origin' => $origin])
                ->assertOk()
                ->assertHeader('Access-Control-Allow-Origin', $origin)
                ->assertHeader('Vary', 'Origin')
                ->assertHeaderMissing('Access-Control-Allow-Credentials');
        }
    }

    public function test_json_write_preflights_are_allowed_without_executing_api_routes(): void
    {
        $schedule = '/api/v1/schedules/00000000-0000-4000-8000-000000000099';

        foreach ([['PATCH', '/cells'], ['POST', '/publications']] as [$method, $path]) {
            $response = $this->options($schedule.$path, [], [
                'Origin' => self::FRONTEND,
                'Access-Control-Request-Method' => $method,
                'Access-Control-Request-Headers' => 'content-type, accept',
            ])->assertNoContent()
                ->assertHeader('Access-Control-Allow-Origin', self::FRONTEND)
                ->assertHeader('Access-Control-Allow-Headers', 'accept, content-type')
                ->assertHeader('Access-Control-Max-Age', '600')
                ->assertHeaderMissing('Access-Control-Allow-Credentials')
                ->assertHeaderMissing('X-RateLimit-Limit');

            $this->assertContains($method, explode(', ', $response->headers->get('Access-Control-Allow-Methods')));
            $this->assertContains('Origin', $response->getVary());
            $this->assertContains('Access-Control-Request-Method', $response->getVary());
        }

        $this->getJson('/api/v1')->assertOk()->assertHeader('X-RateLimit-Remaining', '9');
    }

    public function test_unlisted_origins_cannot_read_responses_or_pass_preflight(): void
    {
        foreach ([
            'https://untrusted.example.test',
            self::FRONTEND.'.untrusted.test',
            'http://front.example.test',
            self::FRONTEND.':8443',
            'null',
        ] as $origin) {
            $this->getJson('/api/v1', ['Origin' => $origin])
                ->assertOk()
                ->assertHeaderMissing('Access-Control-Allow-Origin');

            $this->options('/api/v1', [], [
                'Origin' => $origin,
                'Access-Control-Request-Method' => 'PATCH',
                'Access-Control-Request-Headers' => 'content-type',
            ])->assertNoContent()
                ->assertHeaderMissing('Access-Control-Allow-Origin')
                ->assertHeaderMissing('Access-Control-Allow-Methods')
                ->assertHeaderMissing('Access-Control-Allow-Headers');
        }
    }

    public function test_preflight_does_not_advertise_unsupported_methods_or_headers(): void
    {
        $response = $this->options('/api/v1', [], [
            'Origin' => self::FRONTEND,
            'Access-Control-Request-Method' => 'DELETE',
            'Access-Control-Request-Headers' => 'authorization, x-untrusted-header',
        ])->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', self::FRONTEND);

        $methods = explode(', ', $response->headers->get('Access-Control-Allow-Methods'));
        $headers = explode(', ', $response->headers->get('Access-Control-Allow-Headers'));

        $this->assertNotContains('DELETE', $methods);
        $this->assertNotContains('*', $methods);
        $this->assertNotContains('authorization', $headers);
        $this->assertNotContains('x-untrusted-header', $headers);
        $this->assertNotContains('*', $headers);
    }

    public function test_requests_without_an_origin_remain_available_without_a_cors_grant(): void
    {
        $this->getJson('/api/v1')
            ->assertOk()
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_an_empty_allowlist_grants_no_cross_origin_access(): void
    {
        config(['cors.allowed_origins' => []]);

        $this->getJson('/api/v1', ['Origin' => self::FRONTEND])
            ->assertOk()
            ->assertHeaderMissing('Access-Control-Allow-Origin');

        $this->options('/api/v1', [], [
            'Origin' => self::FRONTEND,
            'Access-Control-Request-Method' => 'GET',
        ])->assertNoContent()->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_a_single_allowed_origin_is_never_replaced_by_an_untrusted_origin(): void
    {
        config(['cors.allowed_origins' => [self::FRONTEND]]);

        $this->getJson('/api/v1', ['Origin' => 'https://untrusted.example.test'])
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', self::FRONTEND);
    }

    public function test_rate_limit_errors_and_retry_headers_are_readable_by_an_allowed_frontend(): void
    {
        config(['api.rate_limit_per_second' => 1]);

        $this->getJson('/api/v1', ['Origin' => self::FRONTEND])->assertOk();

        $this->getJson('/api/v1', ['Origin' => self::FRONTEND])
            ->assertStatus(429)
            ->assertHeader('Access-Control-Allow-Origin', self::FRONTEND)
            ->assertHeader('Access-Control-Expose-Headers', 'Retry-After, X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset')
            ->assertHeader('Retry-After', '1')
            ->assertJsonPath('error.code', 'RATE_LIMIT_EXCEEDED')
            ->assertJsonPath('error.details.retryAfter', 1);
    }

    public function test_the_health_check_remains_outside_the_cors_policy(): void
    {
        $this->getJson('/up', ['Origin' => self::FRONTEND])
            ->assertOk()
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }
}
