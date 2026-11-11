<?php

use App\Http\Middleware\UserJwtAuthentication;
use App\Http\Middleware\WorkerJwtAuthentication;
use App\Http\Middleware\UserOrWorkerVoiceJwt;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

$application = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'user.jwt' => UserJwtAuthentication::class,
            'worker.jwt' => WorkerJwtAuthentication::class,
            'voice.jwt' => UserOrWorkerVoiceJwt::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\InvalidArgumentException $exception, \Illuminate\Http\Request $request) {
            $action = $request->route()?->getActionName() ?? '';
            if (str_starts_with($action, \App\Http\Controllers\WebsiteController::class.'@')) {
                return redirect('/api/login', 303)->with('error', $exception->getMessage());
            }
            return null;
        });
    })->create();

// Hostinger serves this repository from public_html/billing-system-laravel,
// while keeping environment data and writable files in a private sibling of
// public_html. Leave the normal standalone layout untouched.
$applicationPath = $application->basePath();
$publicHtmlPath = dirname($applicationPath);
$runtimePath = dirname($publicHtmlPath).DIRECTORY_SEPARATOR.'billing-system-runtime';

if (strtolower(basename($publicHtmlPath)) === 'public_html') {
    $application->useEnvironmentPath($runtimePath);
    $application->useStoragePath($runtimePath.DIRECTORY_SEPARATOR.'storage');
    $application->usePublicPath($publicHtmlPath);
}

return $application;
