<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ApiDocumentationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'api.documentation_enabled' => true,
            'api.rate_limit_per_second' => 1000,
        ]);
    }

    public function test_swagger_ui_loads_local_assets_and_the_local_specification_without_a_session(): void
    {
        $response = $this->get('/docs')
            ->assertOk()
            ->assertHeaderMissing('Set-Cookie');

        $this->assertStringStartsWith('text/html', $response->headers->get('Content-Type'));
        $html = str_replace('\\/', '/', $response->getContent());
        $this->assertStringContainsString('SwaggerUIBundle', $html);
        $this->assertStringContainsString('/docs/openapi.json', $html);
        $this->assertStringContainsString('/docs/assets/swagger-ui.css', $html);
        $this->assertStringContainsString('/docs/assets/swagger-ui-bundle.js', $html);
        $this->assertMatchesRegularExpression('/validatorUrl\s*:\s*null/', $html);
        $this->assertMatchesRegularExpression('/queryConfigEnabled\s*:\s*false/', $html);
        $this->assertDoesNotMatchRegularExpression('/<(?:script|link)\b[^>]*(?:src|href)=["\']https?:\/\//', $html);
    }

    public function test_the_specification_documents_every_api_route_and_uses_the_current_server(): void
    {
        $document = $this->specification();

        $this->assertSame('3.0.3', $document['openapi']);
        $this->assertNotEmpty($document['info']['title']);
        $this->assertNotEmpty($document['info']['version']);
        $this->assertSame('/', $document['servers'][0]['url']);

        $actualOperations = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1') && $route->uri() !== 'up') {
                continue;
            }

            foreach ($route->methods() as $method) {
                if ($method !== 'HEAD') {
                    $actualOperations[] = strtolower($method).' /'.$route->uri();
                }
            }
        }

        $documentedOperations = [];
        $operationIds = [];
        foreach ($document['paths'] as $path => $item) {
            foreach ($item as $method => $operation) {
                if (! in_array($method, ['get', 'post', 'put', 'patch', 'delete', 'options', 'head', 'trace'], true)) {
                    continue;
                }

                $documentedOperations[] = $method.' '.$path;
                $operationIds[] = $operation['operationId'];
                $this->assertNotEmpty($operation['summary'], $method.' '.$path);
                $this->assertNotEmpty($operation['responses'], $method.' '.$path);
            }
        }

        $this->assertEqualsCanonicalizing($actualOperations, $documentedOperations);
        $this->assertCount(count($operationIds), array_unique($operationIds));
    }

    public function test_all_internal_schema_and_response_references_resolve(): void
    {
        $document = $this->specification();
        $referenceCount = 0;

        $inspect = function (array $node) use (&$inspect, $document, &$referenceCount): void {
            foreach ($node as $key => $value) {
                if ($key === '$ref') {
                    $referenceCount++;
                    $this->assertIsString($value);
                    $this->assertStringStartsWith('#/', $value);
                    $target = $document;

                    foreach (explode('/', substr($value, 2)) as $segment) {
                        $segment = str_replace(['~1', '~0'], ['/', '~'], rawurldecode($segment));
                        $this->assertIsArray($target, $value);
                        $this->assertArrayHasKey($segment, $target, $value);
                        $target = $target[$segment];
                    }
                } elseif (is_array($value)) {
                    $inspect($value);
                }
            }
        };

        $inspect($document);
        $this->assertGreaterThan(0, $referenceCount);
    }

    public function test_only_the_required_swagger_assets_are_available_with_correct_content_types(): void
    {
        foreach ([
            'swagger-ui.css' => ['text/css'],
            'swagger-ui-bundle.js' => ['application/javascript', 'text/javascript'],
            'favicon-32x32.png' => ['image/png'],
        ] as $asset => $contentTypes) {
            $response = $this->get('/docs/assets/'.$asset)->assertOk();
            $this->assertContains(explode(';', $response->headers->get('Content-Type'))[0], $contentTypes);
        }

        foreach (['swagger-ui.js.map', '.env', 'composer.json', '..%2F..%2F.env'] as $asset) {
            $this->get('/docs/assets/'.$asset)->assertNotFound();
        }
    }

    public function test_disabling_documentation_hides_the_ui_specification_and_assets(): void
    {
        config(['api.documentation_enabled' => false]);

        foreach (['/docs', '/docs/openapi.json', '/docs/assets/swagger-ui.css', '/docs/assets/swagger-ui-bundle.js'] as $path) {
            $this->get($path)->assertNotFound();
        }
    }

    public function test_documentation_requests_share_the_api_rate_limit(): void
    {
        config(['api.rate_limit_per_second' => 1]);
        $this->freezeTime();

        $this->get('/docs')->assertOk();
        $this->getJson('/api/v1')
            ->assertStatus(429)
            ->assertHeader('Retry-After', '1')
            ->assertJsonPath('error.code', 'RATE_LIMIT_EXCEEDED');
    }

    /** @return array<string, mixed> */
    private function specification(): array
    {
        $response = $this->getJson('/docs/openapi.json')->assertOk();
        $this->assertStringStartsWith('application/json', $response->headers->get('Content-Type'));

        return $response->json();
    }
}
