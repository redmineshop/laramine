<?php

namespace App\Domain\TimeEntries;

use App\Domain\DomainException;

/**
 * Parses and formats a time-entry hours value.
 *
 * Accepted input is a positive decimal (`1.5` or `1,5`), a clock pair
 * (`1:30`, minutes divided by 60), or an hours-and-minutes phrase
 * (`2h15m`, `2h`, `45m`). Stored hours stay a float. Display follows
 * `timespan_format`: `minutes` renders `h:mm`, `decimal` renders two
 * fractional digits with a thousands separator.
 */
final class HourValue
{
    public static function parse(mixed $value): float
    {
        if (is_int($value)) {
            $number = (float) $value;
        } elseif (is_float($value)) {
            $number = $value;
        } elseif (is_string($value)) {
            $number = self::fromString($value);
        } else {
            throw new DomainException('Hours must be a number greater than zero.');
        }

        if (! is_finite($number) || $number <= 0) {
            throw new DomainException('Hours must be a number greater than zero.');
        }

        return $number;
    }

    public static function format(float $hours, string $timespanFormat): string
    {
        if (strtolower($timespanFormat) === 'decimal') {
            return number_format($hours, 2, '.', ',');
        }

        $negative = $hours < 0;
        $absolute = abs($hours);
        $whole = (int) floor($absolute + 1e-9);
        $minutes = (int) round(($absolute - $whole) * 60);
        if ($minutes === 60) {
            $whole++;
            $minutes = 0;
        }

        return ($negative ? '-' : '').$whole.':'.str_pad((string) $minutes, 2, '0', STR_PAD_LEFT);
    }

    private static function fromString(string $value): float
    {
        $text = trim($value);
        if (preg_match('/^(\d+):(\d+)$/', $text, $clock) === 1) {
            return (float) $clock[1] + ((float) $clock[2] / 60);
        }
        if (preg_match('/^(\d+(?:[.,]\d+)?)h(?:\s*(\d+)m?)?$/', $text, $phrase) === 1) {
            $hours = (float) str_replace(',', '.', $phrase[1]);
            $minutes = isset($phrase[2]) ? (float) $phrase[2] : 0.0;

            return $hours + ($minutes / 60);
        }
        if (preg_match('/^(\d+)m$/', $text, $minutesOnly) === 1) {
            return (float) $minutesOnly[1] / 60;
        }
        if (preg_match('/^(\d+(?:[.,]\d+)?)$/', $text, $decimal) === 1) {
            return (float) str_replace(',', '.', $decimal[1]);
        }

        throw new DomainException('Hours must be a number greater than zero.');
    }
}
