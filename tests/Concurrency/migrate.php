<?php

use Illuminate\Contracts\Console\Kernel;

$app = require __DIR__.'/bootstrap.php';

$kernel = $app->make(Kernel::class);
$status = $kernel->call('migrate', ['--force' => true]);
echo $kernel->output();
exit($status);
