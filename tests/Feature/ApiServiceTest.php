<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiServiceTest extends TestCase
{
    public function test_api_returns_service_information_without_a_session_cookie(): void
    {
        $this->get('/api/v1')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertHeaderMissing('Set-Cookie')
            ->assertExactJson([
                'service' => config('app.name'),
                'status' => 'ok',
            ]);
    }

    public function test_health_endpoint_is_available(): void
    {
        $this->getJson('/up')
            ->assertOk()
            ->assertExactJson(['status' => 'up']);
    }

    public function test_root_returns_a_json_error_instead_of_a_welcome_page(): void
    {
        $this->get('/', ['Accept' => 'text/html'])
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonStructure(['message']);
    }
}
