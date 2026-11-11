<?php

namespace App\Services;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;

class RegisteredClientAuthService
{
    public function email(?string $email): string
    {
        if (trim((string)$email) === '') throw new InvalidArgumentException('Email is required');
        $email = strtolower(trim($email));
        if (!preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email)) throw new InvalidArgumentException('Valid email is required');
        return $email;
    }

    public function phone(?string $phone): string
    {
        if (trim((string)$phone) === '') throw new InvalidArgumentException('Phone number is required');
        $digits = preg_replace('/\D/', '', $phone);
        if (strlen($digits) > 10) $digits = substr($digits, -10);
        if (strlen($digits) !== 10) throw new InvalidArgumentException('Phone number must be 10 digits after removing country code');
        return $digits;
    }

    private function password(?string $password): void
    {
        if ($password === null || mb_strlen($password) < 6) throw new InvalidArgumentException('Password must be at least 6 characters');
    }

    private function unique(string $email, string $phone): void
    {
        if (DB::table('registered_clients')->where('phone_number', $phone)->exists()) throw new InvalidArgumentException('Client phone number is already registered');
        if (DB::table('registered_clients')->whereRaw('LOWER(email) = ?', [$email])->exists()) throw new InvalidArgumentException('Client email is already registered');
    }

    private function sendOtp(string $email, string $phone): void
    {
        $otp = $this->generateOtp();
        DB::table('registered_client_otps')->insert(['email' => $email, 'phone_number' => $phone, 'otp_hash' => Hash::make($otp), 'expires_at' => now()->addMinutes(10), 'attempts' => 0, 'verified' => false, 'created_at' => now()]);
        Mail::raw('Your OTP is '.$otp.'. It expires in 10 minutes.', function ($message) use ($email): void {
            $message->to($email)->subject('Your client portal OTP');
        });
    }

    protected function generateOtp(): string
    {
        return sprintf('%06d', random_int(0, 999999));
    }

    public function requestRegistrationOtp(?string $email, ?string $phone): void
    {
        $email = $this->email($email); $phone = $this->phone($phone);
        $this->unique($email, $phone);
        $this->sendOtp($email, $phone);
    }

    public function requestPasswordResetOtp(?string $email): void
    {
        $email = $this->email($email);
        $client = DB::table('registered_clients')->whereRaw('LOWER(email) = ?', [$email])->where('active', true)->first();
        if (!$client) throw new InvalidArgumentException('Client not found');
        $this->sendOtp($email, $client->phone_number);
    }

    private function verifyOtp(string $email, string $phone, ?string $otp): void
    {
        $row = DB::table('registered_client_otps')->whereRaw('LOWER(email) = ?', [$email])->where('phone_number', $phone)->where('verified', false)->orderByDesc('created_at')->orderByDesc('id')->lockForUpdate()->first();
        if (!$row) throw new InvalidArgumentException('OTP not found. Please request a new OTP.');
        if (now()->greaterThan($row->expires_at)) throw new InvalidArgumentException('OTP expired. Please request a new OTP.');
        if ($row->attempts >= 5) throw new InvalidArgumentException('Too many OTP attempts. Please request a new OTP.');
        if (trim((string)$otp) === '') throw new InvalidArgumentException('OTP is required');
        DB::table('registered_client_otps')->where('id', $row->id)->increment('attempts');
        if (!Hash::check(trim($otp), $row->otp_hash)) throw new InvalidArgumentException('Invalid OTP');
        DB::table('registered_client_otps')->where('id', $row->id)->update(['verified' => true]);
    }

    public function verifyRegistration(?string $email, ?string $phone, ?string $otp, ?string $password): object
    {
        $email = $this->email($email); $phone = $this->phone($phone); $this->password($password);
        $this->unique($email, $phone);
        return DB::transaction(function () use ($email, $phone, $otp, $password): object {
            $this->verifyOtp($email, $phone, $otp);
            $id = DB::table('registered_clients')->insertGetId(['email' => $email, 'phone_number' => $phone, 'password_hash' => Hash::make($password), 'email_verified' => true, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
            // Existing business clients with this phone become visible to the newly registered client.
            DB::table('clients')->where('phoneNumber', $phone)->update(['registered_client_id' => $id]);
            return DB::table('registered_clients')->where('id', $id)->first();
        });
    }

    public function resetPassword(?string $email, ?string $otp, ?string $password): void
    {
        $email = $this->email($email); $this->password($password);
        $client = DB::table('registered_clients')->whereRaw('LOWER(email) = ?', [$email])->where('active', true)->first();
        if (!$client) throw new InvalidArgumentException('Client not found');
        DB::transaction(function () use ($email, $otp, $password, $client): void {
            $this->verifyOtp($email, $client->phone_number, $otp);
            DB::table('registered_clients')->where('id', $client->id)->update(['password_hash' => Hash::make($password), 'updated_at' => now()]);
        });
    }

    public function login(?string $phone, ?string $password): ?array
    {
        $phone = $this->phone($phone);
        if (!$password) return null;
        $client = DB::table('registered_clients')->where('phone_number', $phone)->where('active', true)->first();
        if (!$client || !Hash::check($password, $client->password_hash)) return null;
        $secret = config('legacy.jwt.secret');
        if (!is_string($secret) || strlen($secret) < 32) throw new \RuntimeException('LEGACY_JWT_SECRET must be at least 32 bytes for HS256.');
        return ['message' => 'Login successful', 'token' => JWT::encode(['sub' => (string)$client->id, 'tokenType' => 'CLIENT', 'iat' => time()], $secret, 'HS256'), 'clientId' => (string)$client->id];
    }
}
