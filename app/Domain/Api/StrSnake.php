<?php

namespace App\Domain\Api;

/**
 * Turns a Redmine STI name such as TimeEntry into time_entry.
 */
final class StrSnake
{
    public static function of(string $value): string
    {
        $snake = preg_replace('/(?<!^)[A-Z]/', '_$0', $value);

        return strtolower(is_string($snake) ? $snake : $value);
    }
}
