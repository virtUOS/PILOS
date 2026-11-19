<?php

declare(strict_types=1);

$serviceUrl = env('RECAP_SERVICE_URL');
$frontendToken = env('RECAP_FRONTEND_TOKEN');

return [
    // The feature stays off unless the service URL and the token are set as well
    'enabled' => (bool) env('RECAP_ENABLED', false) && ! empty($serviceUrl) && ! empty($frontendToken),
    'meta_prefix' => env('RECAP_META_PREFIX', 'recap'),
    'service_url' => $serviceUrl,
    'frontend_token' => $frontendToken,
    'server_timeout' => (int) env('RECAP_SERVER_TIMEOUT', 10),
    'server_connect_timeout' => (int) env('RECAP_SERVER_CONNECT_TIMEOUT', 20),
];
