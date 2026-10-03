<?php

namespace App\Domain\Issues\History;

/**
 * Builds the visible sentence for one attribute diff.
 *
 * Display values are already resolved (status names, integer done ratios).
 */
final class JournalDetailFormatter
{
    public function attribute(string $label, ?string $old, ?string $new): ?JournalPropertyLine
    {
        $oldValue = $this->blank($old);
        $newValue = $this->blank($new);
        if ($oldValue === null && $newValue === null) {
            return null;
        }
        if ($oldValue === null) {
            return JournalPropertyLine::setTo($label, $newValue);
        }
        if ($newValue === null) {
            return JournalPropertyLine::deleted($label, $oldValue);
        }
        if ($oldValue === $newValue) {
            return null;
        }

        return JournalPropertyLine::changed($label, $oldValue, $newValue);
    }

    private function blank(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value;
    }
}
