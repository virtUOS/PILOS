<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('RECAP_ENABLED', false),
    'meta_prefix' => env('RECAP_META_PREFIX', 'recap'),
];
