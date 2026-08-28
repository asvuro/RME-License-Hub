<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

abstract class DatabaseTestCase extends BaseTestCase
{
    use RefreshDatabase;
}
