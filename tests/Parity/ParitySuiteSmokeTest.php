<?php

namespace Tests\Parity;

use Tests\TestCase;

/**
 * Keeps the Parity suite executable before Redmine fixture tests exist.
 * This does not assert behavioral compatibility.
 */
class ParitySuiteSmokeTest extends TestCase
{
    public function test_application_boots_for_future_parity_fixtures(): void
    {
        $this->assertNotNull($this->app);
        $this->assertSame('testing', $this->app->environment());
    }
}
