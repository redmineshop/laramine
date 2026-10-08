<?php

namespace App\Domain\Queries;

use Throwable;

/**
 * `options.display_type` for an IssueQuery.
 *
 * A missing value is `list`, the only display type on a 7.0.1 IssueQuery.
 * `board` is a Laramine status-column extension and is accepted only when
 * `redmine.issue_query_board` is true. Any other value is rejected.
 * Gantt and calendar are not display types here.
 */
final class IssueQueryDisplay
{
    public const LIST = 'list';

    public const BOARD = 'board';

    /**
     * @param  array<string, mixed>|null  $options
     */
    public static function resolve(?array $options, ?bool $allowBoard = null): string
    {
        $allowBoard ??= self::boardEnabled();

        if ($options === null || ! array_key_exists('display_type', $options) || $options['display_type'] === null) {
            return self::LIST;
        }

        $value = $options['display_type'];
        if ($value === self::LIST || ($value === self::BOARD && $allowBoard)) {
            return $value;
        }

        $suffix = is_string($value) && $value !== '' ? ': '.$value : '';

        throw new QueryValidationException('Query display type is not available'.$suffix.'.');
    }

    /**
     * Status columns are outside the 7.0.1 IssueQuery pin.
     */
    public static function boardEnabled(): bool
    {
        if (! function_exists('config')) {
            return false;
        }

        try {
            return config('redmine.issue_query_board') === true;
        } catch (Throwable) {
            return false;
        }
    }
}
