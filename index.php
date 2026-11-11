<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$appRoot = __DIR__ . '/billing-system-laravel';
$runtimeRoot = dirname(__DIR__) . '/billing-system-runtime';

if (file_exists($maintenance = $runtimeRoot . '/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $appRoot . '/vendor/autoload.php';
/** @var Application $app */
$app = require_once $appRoot . '/bootstrap/app.php';
$app->handleRequest(Request::capture());
