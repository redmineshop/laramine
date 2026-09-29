<?php

namespace App\Domain\Queries;

/**
 * Issue filter operators from the Redmine 7.0.1 catalog (names and semantics only).
 *
 * Eighteen operators are shipped. Twenty-three stay deferred until journals,
 * relation filters, or the remaining relative dates exist.
 */
final class OperatorMatrix
{
    /**
     * @var list<string>
     */
    public const SHIPPED = [
        '=', '!', 'o', 'c', '!*', '*', '>=', '<=', '><',
        't', 'ld', 'w', 'lw', 'm', 'lm', 'y', '~', '!~',
    ];

    /**
     * @var list<string>
     */
    public const DEFERRED = [
        '<t+', '>t+', '><t+', 't+', 'nd', 'nw', 'nm',
        '>t-', '<t-', '><t-', 't-', 'l2w',
        '*~', '^', '$',
        '=p', '=!p', '!p', '*o', '!o',
        'ev', '!ev', 'cf',
    ];

    /**
     * Full operator list for each filter type.
     *
     * @var array<string, list<string>>
     */
    private const BY_TYPE = [
        'list' => ['=', '!'],
        'list_with_history' => ['=', '!', 'ev', '!ev', 'cf'],
        'list_status' => ['o', '=', '!', 'ev', '!ev', 'cf', 'c', '*'],
        'list_optional' => ['=', '!', '!*', '*'],
        'list_optional_with_history' => ['=', '!', 'ev', '!ev', 'cf', '!*', '*'],
        'list_subprojects' => ['*', '!*', '=', '!'],
        'date' => [
            '=', '>=', '<=', '><', '<t+', '>t+', '><t+', 't+', 'nd', 't', 'ld',
            'nw', 'w', 'lw', 'l2w', 'nm', 'm', 'lm', 'y', '>t-', '<t-', '><t-', 't-',
            '!*', '*',
        ],
        'date_past' => [
            '=', '>=', '<=', '><', '>t-', '<t-', '><t-', 't-', 't', 'ld',
            'w', 'lw', 'l2w', 'm', 'lm', 'y', '!*', '*',
        ],
        'string' => ['~', '*~', '=', '!~', '!', '^', '$', '!*', '*'],
        'text' => ['~', '*~', '!~', '^', '$', '!*', '*'],
        'search' => ['~', '*~', '!~'],
        'integer' => ['=', '>=', '<=', '><', '!*', '*'],
        'float' => ['=', '>=', '<=', '><', '!*', '*'],
        'hour' => ['=', '>=', '<=', '><', '!*', '*'],
        'relation' => ['=', '!', '=p', '=!p', '!p', '*o', '!o', '!*', '*'],
        'tree' => ['=', '~', '!*', '*'],
    ];

    /**
     * Text filters gain exact `=` so subject and description can match a whole value.
     *
     * @var array<string, list<string>>
     */
    private const EXTRA_SHIPPED = [
        'text' => ['='],
    ];

    /**
     * Tree `*` is in the global shipped set but the parent/child MVP is only `=` and `!*`.
     *
     * @var array<string, list<string>>
     */
    private const HELD_BACK = [
        'tree' => ['*', '~'],
    ];

    public static function assert(string $filterType, string $operator, string $field): void
    {
        $status = self::acceptance($filterType, $operator);
        if ($status === 'shipped') {
            return;
        }

        if ($status === 'deferred') {
            throw new QueryValidationException('Operator '.$operator.' is deferred for '.$field.'.');
        }

        throw new QueryValidationException('Operator '.$operator.' is not valid for '.$field.'.');
    }

    public static function acceptance(string $filterType, string $operator): string
    {
        $full = self::BY_TYPE[$filterType] ?? null;
        if ($full === null) {
            return 'unknown_type';
        }

        $extras = self::EXTRA_SHIPPED[$filterType] ?? [];
        if (! in_array($operator, $full, true) && ! in_array($operator, $extras, true)) {
            return 'invalid';
        }

        if (self::isShipped($filterType, $operator)) {
            return 'shipped';
        }

        return 'deferred';
    }

    private static function isShipped(string $filterType, string $operator): bool
    {
        if (in_array($operator, self::HELD_BACK[$filterType] ?? [], true)) {
            return false;
        }

        if (in_array($operator, self::EXTRA_SHIPPED[$filterType] ?? [], true)) {
            return true;
        }

        return in_array($operator, self::SHIPPED, true);
    }

    /**
     * @return list<string>
     */
    public static function shippedFor(string $filterType): array
    {
        $full = self::BY_TYPE[$filterType] ?? [];
        $shipped = [];
        foreach ([...$full, ...self::EXTRA_SHIPPED[$filterType] ?? []] as $operator) {
            if (self::acceptance($filterType, $operator) === 'shipped' && ! in_array($operator, $shipped, true)) {
                $shipped[] = $operator;
            }
        }

        return $shipped;
    }

    public static function valueMode(string $operator): string
    {
        return match ($operator) {
            'o', 'c', '*', '!*', 't', 'ld', 'w', 'lw', 'm', 'lm', 'y' => 'none',
            '><' => 'two',
            '=', '!', '>=', '<=', '~', '!~' => 'one',
            default => 'unknown',
        };
    }
}
