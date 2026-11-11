<?php

namespace App\Http\Middleware;

use App\Services\LegacyJwtService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class UserOrWorkerVoiceJwt
{
    public function __construct(private readonly LegacyJwtService $jwt, private readonly WorkerJwtAuthentication $worker) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $claims = $this->jwt->decode($request->bearerToken() ?? '');
        } catch (\Throwable) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }
        if (($claims->tokenType ?? 'USER') === 'WORKER') {
            return $this->worker->handle($request, $next);
        }
        if (($claims->tokenType ?? 'USER') !== 'USER' || ! is_string($claims->sub ?? null)) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }
        $request->attributes->set('jwt_subject', $claims->sub);
        $request->attributes->set('jwt_claims', $claims);
        return $next($request);
    }
}
