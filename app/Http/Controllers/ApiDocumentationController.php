<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ApiDocumentationController extends Controller
{
    public function index(): Response
    {
        abort_unless(config('api.documentation_enabled'), 404);

        return response()->view('swagger');
    }

    public function specification(): JsonResponse
    {
        abort_unless(config('api.documentation_enabled'), 404);

        $specification = json_decode(file_get_contents(base_path('openapi.json')), true, flags: JSON_THROW_ON_ERROR);

        return response()->json($specification, headers: ['Cache-Control' => 'no-cache']);
    }

    public function asset(string $asset): BinaryFileResponse
    {
        abort_unless(config('api.documentation_enabled'), 404);

        $contentTypes = [
            'swagger-ui.css' => 'text/css; charset=UTF-8',
            'swagger-ui-bundle.js' => 'application/javascript; charset=UTF-8',
            'favicon-32x32.png' => 'image/png',
        ];
        abort_unless(isset($contentTypes[$asset]), 404);

        return response()->file(base_path('vendor/swagger-api/swagger-ui/dist/'.$asset), [
            'Content-Type' => $contentTypes[$asset],
            'Cache-Control' => 'public, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
