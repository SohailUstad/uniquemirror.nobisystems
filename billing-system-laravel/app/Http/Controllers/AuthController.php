<?php

namespace App\Http\Controllers;

use App\Services\LegacyJwtService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthController
{
    public function register(Request $request): JsonResponse
    {
        $email = $request->input('email');
        $password = $request->input('password');

        if (DB::table('users')->where('email', $email)->exists()) {
            return response()->json(['error' => 'Email already registered'], 400);
        }

        DB::table('users')->insert([
            'email' => $email,
            'password' => Hash::make($password),
        ]);

        return response()->json([
            'message' => 'User registered successfully',
            'email' => $email,
        ], 200);
    }

    public function login(Request $request, LegacyJwtService $jwt): JsonResponse
    {
        $email = $request->input('email');
        $password = $request->input('password');
        $user = DB::table('users')->where('email', $email)->first();

        if ($user !== null && Hash::check($password, $user->password)) {
            return response()->json([
                'message' => 'Login successful',
                'token' => $jwt->issueUserToken($email),
            ], 200);
        }

        return response()->json(['error' => 'Invalid email or password'], 400);
    }
}
