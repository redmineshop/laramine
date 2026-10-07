<?php

namespace App\Domain\Queries;

/**
 * `options.display_type` for an IssueQuery.
 *
 * A missing value is `list`. `board` groups the same rows by status.
 * Any other value is rejected. Gantt and calendar are not display types here.
 */
final class IssueQueryDisplay
{
    public const LIST = 'list';

    public const BOARD = 'board';

    /**
     * @param  array<string, mixed>|null  $options
     */
    public static function resolve(?array $options): string
    {
        if ($options === null || ! array_key_exists('display_type', $options) || $options['display_type'] === null) {
            return self::LIST;
        }

        $value = $options['display_type'];
        if ($value === self::LIST || $value === self::BOARD) {
            return $value;
        }

        $suffix = is_string($value) && $value !== '' ? ': '.$value : '';

        throw new QueryValidationException('Query display type is not available'.$suffix.'.');
    }
}
