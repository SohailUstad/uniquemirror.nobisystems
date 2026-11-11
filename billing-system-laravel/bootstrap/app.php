<?php

use App\Http\Middleware\UserJwtAuthentication;
use App\Http\Middleware\WorkerJwtAuthentication;
use App\Http\Middleware\UserOrWorkerVoiceJwt;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
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
