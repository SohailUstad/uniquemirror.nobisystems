<?php

namespace Tests\Unit;

use App\Support\PasswordVerifier;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordVerifierTest extends TestCase
{
    public function test_it_verifies_legacy_bcrypt_2a_hashes(): void
    {
        $hash = crypt('legacy-password', '$2a$10$usesomesillystringfore');

        $this->assertStringStartsWith('$2a$', $hash);
        $this->assertTrue(PasswordVerifier::check('legacy-password', $hash));
        $this->assertFalse(PasswordVerifier::check('incorrect-password', $hash));
    }

    public function test_it_keeps_using_laravel_for_native_hashes(): void
    {
        $hash = Hash::make('laravel-password');

        $this->assertTrue(PasswordVerifier::check('laravel-password', $hash));
        $this->assertFalse(PasswordVerifier::check('incorrect-password', $hash));
    }
}
