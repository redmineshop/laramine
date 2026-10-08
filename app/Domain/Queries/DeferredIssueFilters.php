<?php

namespace App\Domain\Queries;

/**
 * Chained custom-field suffixes that are not compiled.
 *
 * Version fields accept `due_date` and `status` before this check runs.
 * `cf_N.cf_M` and `project|author|assigned_to|fixed_version.cf_N` are compiled
 * by the association custom-field filter. Any other suffix is rejected.
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
