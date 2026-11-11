<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }} — API документация</title>
    <link rel="icon" type="image/png" href="{{ route('docs.asset', ['asset' => 'favicon-32x32.png'], false) }}">
    <link rel="stylesheet" href="{{ route('docs.asset', ['asset' => 'swagger-ui.css'], false) }}">
    <style>
        html { box-sizing: border-box; overflow-y: scroll; }
        *, *::before, *::after { box-sizing: inherit; }
        body { margin: 0; background: #fafafa; }
        .docs-intro { padding: 20px max(20px, calc((100% - 1460px) / 2)); background: #fff; border-bottom: 1px solid #e5e7eb; font: 14px/1.6 system-ui, sans-serif; color: #374151; }
        .docs-intro a { color: #2454c6; }
        .docs-intro p { margin: 8px 0 0; }
    </style>
</head>
<body>
    <header class="docs-intro">
        <strong>{{ config('app.name') }} API</strong> ·
        <a href="{{ route('docs.openapi', [], false) }}">OpenAPI JSON</a>
        <p>Для проверки откройте операцию и нажмите Try it out. PATCH и POST изменяют данные: сначала получите текущую версию графика через GET /calendar.</p>
    </header>
    <div id="swagger-ui"></div>
    <script src="{{ route('docs.asset', ['asset' => 'swagger-ui-bundle.js'], false) }}"></script>
    <script>
        window.ui = SwaggerUIBundle({
            url: @js(route('docs.openapi', [], false)),
            dom_id: '#swagger-ui',
            deepLinking: true,
            filter: true,
            displayRequestDuration: true,
            displayOperationId: true,
            docExpansion: 'list',
            defaultModelsExpandDepth: 1,
            defaultModelExpandDepth: 2,
            supportedSubmitMethods: ['get', 'head', 'post', 'patch'],
            tryItOutEnabled: false,
            validatorUrl: null,
            queryConfigEnabled: false,
            persistAuthorization: false,
            withCredentials: false,
            presets: [SwaggerUIBundle.presets.apis],
            layout: 'BaseLayout',
        });
    </script>
</body>
</html>
