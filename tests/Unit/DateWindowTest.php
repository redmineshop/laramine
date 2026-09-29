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

    public function test_offset_windows_around_2026_09_29(): void
    {
        $window = new DateWindow(CarbonImmutable::parse('2026-09-29', 'UTC')->startOfDay());

        $this->assertSame(['2026-09-30', '2026-09-30'], $this->span($window, 'nd'));
        $this->assertSame(['2026-10-05', '2026-10-11'], $this->span($window, 'nw'));
        $this->assertSame(['2026-10-01', '2026-10-31'], $this->span($window, 'nm'));
        $this->assertSame(['2026-09-14', '2026-09-27'], $this->span($window, 'l2w'));
        $this->assertSame(['2026-10-02', '2026-10-02'], $this->span($window, 't+', 3));
        $this->assertSame(['2026-09-27', '2026-09-27'], $this->span($window, 't-', 2));
        $this->assertSame([null, '2026-09-30'], $this->span($window, '<t+', 2));
        $this->assertSame(['2026-10-01', null], $this->span($window, '>t+', 1));
        $this->assertSame(['2026-09-30', '2026-10-02'], $this->span($window, '><t+', 3));
        $this->assertSame(['2026-09-26', '2026-09-29'], $this->span($window, '>t-', 3));
        $this->assertSame([null, '2026-09-25'], $this->span($window, '<t-', 3));
        $this->assertSame(['2026-09-26', '2026-09-28'], $this->span($window, '><t-', 3));
        $this->assertTrue($window->calendarBound('><t+', 0)->empty);
        $this->assertTrue($window->calendarBound('><t-', 0)->empty);
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function span(DateWindow $window, string $operator, int $days = 0): array
    {
        $bound = $window->calendarBound($operator, $days);

        return [$bound->from, $bound->to];
    }
}
