<?php

declare(strict_types=1);

return [
    'admin_cache_ttl' => (int) env('DASHBOARD_ADMIN_CACHE_TTL', 300),
    'admin_kpi_cache_key' => 'dashboard:admin:kpi:v1',
    'admin_completion_rate_cache_key' => 'dashboard:admin:completion-rate:v1',
    'admin_cache_generation_key' => 'dashboard:admin:generation:v1',
];
