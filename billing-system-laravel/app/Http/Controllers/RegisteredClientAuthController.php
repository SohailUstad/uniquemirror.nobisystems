<?php

namespace App\Http\Controllers;

use App\Services\RegisteredClientAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class RegisteredClientAuthController extends Controller
{
    public function __construct(private readonly RegisteredClientAuthService $auth) {}

    private function perform(callable $action): JsonResponse
    {
        try { return response()->json($action()); }
        catch (InvalidArgumentException $e) { return response()->json(['error' => $e->getMessage()], 400); }
    }

    public function requestRegistrationOtp(Request $request): JsonResponse
    {
        return $this->perform(function () use ($request) { $this->auth->requestRegistrationOtp($request->input('email'), $request->input('phoneNumber')); return ['message' => 'OTP sent successfully']; });
    }
    public function verifyRegistration(Request $request): JsonResponse
    {
        return $this->perform(function () use ($request) { $client = $this->auth->verifyRegistration($request->input('email'), $request->input('phoneNumber'), $request->input('otp'), $request->input('password')); return ['message' => 'Client registered successfully', 'clientId' => $client->id, 'email' => $client->email, 'phoneNumber' => $client->phone_number]; });
    }
    public function login(Request $request): JsonResponse
    {
        try { $result=$this->auth->login($request->input('phoneNumber'), $request->input('password')); return $result ? response()->json($result) : response()->json(['error'=>'Invalid phone number or password'],400); }
        catch (InvalidArgumentException $e) { return response()->json(['error'=>$e->getMessage()],400); }
    }
    public function requestPasswordResetOtp(Request $request): JsonResponse
    {
        return $this->perform(function () use ($request) { $this->auth->requestPasswordResetOtp($request->input('email')); return ['message' => 'OTP sent successfully']; });
    }
    public function resetPassword(Request $request): JsonResponse
    {
        return $this->perform(function () use ($request) { $this->auth->resetPassword($request->input('email'), $request->input('otp'), $request->input('password')); return ['message' => 'Password updated successfully']; });
    }
}
