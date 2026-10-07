<?php

namespace Tests\Parity;

use Tests\TestCase;

/**
 * Boots the application for the Parity suite.
 *
 * Row loading is covered by Redmine701FixtureHarnessTest. This test does not
 * compare Laramine to Redmine and does not mark any parity row VERIFIED.
 */
class ParitySuiteSmokeTest extends TestCase
{
    public function test_application_boots_for_future_parity_fixtures(): void
    {
        $this->assertNotNull($this->app);
        $this->assertSame('testing', $this->app->environment());
    }
}
