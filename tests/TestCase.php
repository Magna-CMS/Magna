<?php

namespace Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\GuardsTheDevelopmentDatabase;

abstract class TestCase extends BaseTestCase
{
    use GuardsTheDevelopmentDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Asked once the connection is real, before any test can write through
        // it. See the trait for why this is worth the microsecond.
        $this->assertNotUsingTheDevelopmentDatabase();

        Model::preventLazyLoading();
    }
}
