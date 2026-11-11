<?php

namespace App\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use stdClass;

class LegacyJwtService
{
    private const DEFAULT_EXPIRATION_MS = 86400000;

    public function issueUserToken(string $email): string
    {
        $now = time();
        $expirationSeconds = intdiv((int) config('legacy.jwt.expiration_ms', self::DEFAULT_EXPIRATION_MS), 1000);

        return JWT::encode([
            'sub' => $email,
            'tokenType' => 'USER',
            'iat' => $now,
            'exp' => $now + $expirationSeconds,
        ], $this->secret(), 'HS256');
    }

    public function decode(string $token): stdClass
    {
        return JWT::decode($token, new Key($this->secret(), 'HS256'));
    }

    private function secret(): string
    {
        $secret = config('legacy.jwt.secret');
        if (! is_string($secret) || $secret === '') {
            throw new \RuntimeException('LEGACY_JWT_SECRET must be configured.');
        }
        if (strlen($secret) < 32) {
            throw new \InvalidArgumentException('LEGACY_JWT_SECRET must be at least 32 bytes for HS256.');
        }

        return $secret;
    }
}
