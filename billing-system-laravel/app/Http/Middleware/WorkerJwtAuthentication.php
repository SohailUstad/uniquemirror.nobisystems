<?php

namespace App\Http\Middleware;

use App\Services\LegacyJwtService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class WorkerJwtAuthentication
{
    public function __construct(private readonly LegacyJwtService $jwt) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $claims = $this->jwt->decode($request->bearerToken() ?? '');
        } catch (\Throwable) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }
        if (($claims->tokenType ?? null) !== 'WORKER' || ! is_string($claims->sub ?? null)
            || ! is_int($claims->workerId ?? null) || ! is_string($claims->workerCode ?? null)) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }
        $worker = DB::table('workers')->join('users', 'users.id', '=', 'workers.user_id')
            ->where('workers.id', $claims->workerId)->where('workers.code', $claims->workerCode)
            ->where('workers.active', true)->where('users.email', $claims->sub)
            ->select('workers.*', 'users.email as owner_email')->first();
        if ($worker === null) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }
        $path = '/'.$request->path();
        $change = $path === '/api/worker-auth/change-password';
        $self = str_starts_with($path, '/api/worker/');
        $voice = $request->isMethod('GET') && preg_match('~^/api/orders/[^/]+/voice-notes(?:/[^/]+/stream)?$~', $path);
        if (! $change && ! $self && ! $voice) {
            return response()->json(['error' => 'Workers cannot access this resource', 'mustChangePassword' => false], 403);
        }
        if (! $change && $worker->must_change_password) {
            return response()->json(['error' => 'Change your password before using the app', 'mustChangePassword' => true], 403);
        }
        $request->attributes->set('jwt_subject', $claims->sub);
        $request->attributes->set('jwt_claims', $claims);
        $request->attributes->set('worker_id', $worker->id);
        $request->attributes->set('worker', $worker);
        return $next($request);
    }
}
