<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    // Laravel 12 discovers the application through bootstrap/app.php.
    public function createApplication(): \Illuminate\Foundation\Application
    {
        return require __DIR__.'/../bootstrap/app.php';
    }
}
