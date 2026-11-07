<?php

return [
    // Empty by default: access is denied until a trusted network is configured.
    'allowed_networks' => array_values(array_filter(array_map('trim', explode(',', env('REPORTS_ALLOWED_NETWORKS', ''))))),
];
