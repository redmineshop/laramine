<?php

namespace Tests\Parity;

use Tests\TestCase;

/**
 * Keeps the Parity suite executable before Redmine fixture tests exist.
 *
 * P0 tables can be migrated. This suite does not compare them to a Redmine
 * database and does not mark any parity row VERIFIED.
 */
class ParitySuiteSmokeTest extends TestCase
{
    public function test_application_boots_for_future_parity_fixtures(): void
    {
        $this->assertNotNull($this->app);
        $this->assertSame('testing', $this->app->environment());
    }
}
