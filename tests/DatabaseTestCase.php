<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class DatabaseTestCase extends BaseTestCase
{
    use RefreshDatabase;
}
