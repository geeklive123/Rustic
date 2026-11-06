<?php

$proxies = array_values(array_filter(array_map('trim', explode(',', env('TRUSTED_PROXIES', '')))));
if (array_intersect($proxies, ['*', '**', 'REMOTE_ADDR'])) {
    throw new InvalidArgumentException('TRUSTED_PROXIES requires explicit proxy IP addresses or CIDR ranges.');
}

return ['proxies' => $proxies];
