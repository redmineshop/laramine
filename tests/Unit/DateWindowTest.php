<?php

namespace Tests\Unit;

use App\Domain\Queries\DateWindow;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class DateWindowTest extends TestCase
{
    public function test_relative_dates_use_monday_weeks_around_2026_09_29(): void
    {
        $window = new DateWindow(CarbonImmutable::parse('2026-09-29', 'UTC')->startOfDay());

        $this->assertSame(['2026-09-29', '2026-09-29'], $window->dates('t'));
        $this->assertSame(['2026-09-28', '2026-09-28'], $window->dates('ld'));
        $this->assertSame(['2026-09-28', '2026-10-04'], $window->dates('w'));
        $this->assertSame(['2026-09-21', '2026-09-27'], $window->dates('lw'));
        $this->assertSame(['2026-09-01', '2026-09-30'], $window->dates('m'));
        $this->assertSame(['2026-08-01', '2026-08-31'], $window->dates('lm'));
        $this->assertSame(['2026-01-01', '2026-12-31'], $window->dates('y'));
    }
}
