<?php

namespace App\Http\Controllers;

use Firebase\JWT\JWT;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class WorkerAuthController
{
    public function login(Request $request): JsonResponse
    {
        $code = trim((string) $request->input('code', ''));
        $password = $request->input('password');
        if ($code === '' || ! is_string($password) || $password === '') {
            return response()->json(['error' => 'Invalid worker code or password'], 400);
        }
        $worker = DB::table('workers')->join('users', 'users.id', '=', 'workers.user_id')
            ->where('workers.code', $code)->where('workers.active', true)
            ->select('workers.*', 'users.email as owner_email')->first();
        if (! $worker || ! $worker->password_hash || ! Hash::check($password, $worker->password_hash)) {
            return response()->json(['error' => 'Invalid worker code or password'], 400);
        }
        DB::table('workers')->where('id', $worker->id)->update(['last_login_at' => now()]);
        $now = time();
        $secret = config('legacy.jwt.secret');
        if (! is_string($secret) || strlen($secret) < 32) {
            throw new \RuntimeException('LEGACY_JWT_SECRET must be configured with at least 32 bytes.');
        }
        $token = JWT::encode([
            'sub' => $worker->owner_email, 'tokenType' => 'WORKER',
            'workerId' => (int) $worker->id, 'workerCode' => $worker->code,
            'iat' => $now, 'exp' => $now + intdiv((int) config('legacy.jwt.expiration_ms', 86400000), 1000),
        ], $secret, 'HS256');
        return response()->json(['message' => 'Login successful', 'token' => $token, 'worker' => $this->profile($worker)]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $id = $request->attributes->get('worker_id');
        if (! is_int($id)) {
            return response()->json(['error' => 'Worker authentication is required'], 400);
        }
        $worker = DB::table('workers')->where('id', $id)->where('active', true)->first();
        if (! $worker) return response()->json(['error' => 'Worker not found'], 400);
        $current = $request->input('currentPassword');
        $new = $request->input('newPassword');
        if (! is_string($current) || ! $worker->password_hash || ! Hash::check($current, $worker->password_hash)) {
            return response()->json(['error' => 'Current password is incorrect'], 400);
        }
        if (! is_string($new) || mb_strlen($new) < 6) {
            return response()->json(['error' => 'Password must be at least 6 characters'], 400);
        }
        if (Hash::check($new, $worker->password_hash)) {
            return response()->json(['error' => 'New password must be different from the current one'], 400);
        }
        DB::table('workers')->where('id', $id)->update(['password_hash' => Hash::make($new), 'must_change_password' => false, 'updated_at' => now()]);
        $worker->must_change_password = false;
        return response()->json(['message' => 'Password updated successfully', 'worker' => $this->profile($worker)]);
    }

    private function profile(object $worker): array
    {
        return ['workerId' => $worker->id, 'name' => $worker->name, 'code' => $worker->code,
            'phoneNumber' => $worker->phone_number, 'mustChangePassword' => (bool) $worker->must_change_password];
    }
}
