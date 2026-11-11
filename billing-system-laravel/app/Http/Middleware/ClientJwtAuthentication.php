<?php

namespace App\Http\Middleware;

use App\Services\LegacyJwtService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ClientJwtAuthentication
{
    public function __construct(private readonly LegacyJwtService $jwt) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $claims = $this->jwt->decode($request->bearerToken() ?? '');
        } catch (\Throwable) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }
        if (($claims->tokenType ?? null) !== 'CLIENT' || !is_string($claims->sub ?? null) || !ctype_digit($claims->sub)) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }
        $id = (int) $claims->sub;
        if ($id < 1 || !DB::table('registered_clients')->where('id', $id)->where('active', true)->exists()) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }
        $request->attributes->set('registered_client_id', $id);
        return $next($request);
    }
}
