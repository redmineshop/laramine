<?php

namespace Tests\Unit;

use App\Domain\DomainException;
use App\Domain\TimeEntries\HourValue;
use PHPUnit\Framework\TestCase;

class HourValueTest extends TestCase
{
    public function test_parses_decimal_clock_and_phrase_hours(): void
    {
        $this->assertSame(1.5, HourValue::parse('1.5'));
        $this->assertSame(1.5, HourValue::parse('1,5'));
        $this->assertSame(1.5, HourValue::parse('1:30'));
        $this->assertSame(2.25, HourValue::parse('2h15m'));
        $this->assertSame(2.25, HourValue::parse('2h 15'));
        $this->assertSame(1.0, HourValue::parse('1h'));
        $this->assertSame(0.75, HourValue::parse('45m'));
        $this->assertSame(2.0, HourValue::parse(2));
        $this->assertSame(2.5, HourValue::parse(2.5));
    }

    public function test_rejects_zero_and_text(): void
    {
        foreach ([0, -1, '0', '0:00', '0h', 'no', ''] as $value) {
            try {
                HourValue::parse($value);
                $this->fail('Accepted '.var_export($value, true));
            } catch (DomainException $exception) {
                $this->assertSame('Hours must be a number greater than zero.', $exception->getMessage());
            }
        }
    }

    public function test_formats_minutes_and_decimal(): void
    {
        $this->assertSame('1:30', HourValue::format(1.5, 'minutes'));
        $this->assertSame('0:45', HourValue::format(0.75, 'minutes'));
        $this->assertSame('2:00', HourValue::format(2, 'minutes'));
        $this->assertSame('1.50', HourValue::format(1.5, 'decimal'));
        $this->assertSame('1,234.50', HourValue::format(1234.5, 'decimal'));
        $this->assertSame('1:00', HourValue::format(0.999, 'minutes'));
    }
}
