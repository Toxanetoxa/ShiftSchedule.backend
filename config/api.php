<?php

return [
    'rate_limit_per_second' => max(1, (int) env('API_RATE_LIMIT_PER_SECOND', 10)),
    'documentation_enabled' => env('API_DOCS_ENABLED', true),
];
