<?php

namespace App\Domain\Queries;

/**
 * Chained custom-field suffixes that are not compiled.
 *
 * Version fields accept `due_date` and `status` before this check runs.
 * Shipped formats do not define any other chain.
 */
final class DeferredIssueFilters
{
    public static function assert(string $field): void
    {
        if (preg_match('/^cf_\d+\./', $field) === 1) {
            throw new QueryValidationException('Chained custom field filter is not supported: '.$field.'.');
        }
    }
}
