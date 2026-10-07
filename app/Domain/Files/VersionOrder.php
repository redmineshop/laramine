<?php

namespace App\Domain\Files;

/**
 * Order used when the files index lists a project's versions.
 *
 * Ascending comparison: a dated version is before an undated one. Two dated
 * versions use `effective_date`, then id. Two undated versions use the name
 * case-insensitively, then id. The files index shows the reverse of that
 * comparison: undated names descending, then later dates first.
 */
final class VersionOrder
{
    public static function compare(
        ?string $leftDate,
        int $leftId,
        string $leftName,
        ?string $rightDate,
        int $rightId,
        string $rightName,
    ): int {
        $leftDated = $leftDate !== null && $leftDate !== '';
        $rightDated = $rightDate !== null && $rightDate !== '';
        if ($leftDated !== $rightDated) {
            return $leftDated ? -1 : 1;
        }
        if ($leftDate !== null && $leftDate !== '' && $rightDate !== null && $rightDate !== '') {
            if ($leftDate !== $rightDate) {
                return $leftDate <=> $rightDate;
            }

            return $leftId <=> $rightId;
        }

        $byName = mb_strtolower($leftName) <=> mb_strtolower($rightName);
        if ($byName !== 0) {
            return $byName;
        }

        return $leftId <=> $rightId;
    }

    /**
     * Files-index order: the reverse of {@see compare()}.
     */
    public static function forFiles(
        ?string $leftDate,
        int $leftId,
        string $leftName,
        ?string $rightDate,
        int $rightId,
        string $rightName,
    ): int {
        return self::compare($rightDate, $rightId, $rightName, $leftDate, $leftId, $leftName);
    }
}
