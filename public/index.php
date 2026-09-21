<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Refuse an unsupported PHP version in language every PHP since 5 can parse,
// and BEFORE the autoloader below.
//
// Magna\Install\Requirements already checks this and says it kindly, but it
// lives behind vendor/autoload.php, where Composer's own platform_check.php
// throws first: the operator's first contact with the product is an uncaught
// RuntimeException and a stack trace printing absolute server paths. Keep this
// literal in step with composer.json's "php" constraint and Requirements.php —
// nothing here can autoload a shared constant, and a guardrail test pins the
// three together.
if (version_compare(PHP_VERSION, '8.3.0', '<')) {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'Magna CMS requires PHP 8.3 or newer. This server is running PHP '.PHP_VERSION.".\n");
        exit(1);
    }

    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    require __DIR__.'/../bootstrap/unsupported-php.php';
    exit(1);
}

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
