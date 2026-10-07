<?php

namespace App\Domain\Auth;

use App\Domain\Settings\SettingValue;

/**
 * Rules applied when a password is chosen. Checking an existing digest
 * does not apply them.
 */
final class PasswordPolicy
{
    public function __construct(
        private readonly SettingValue $settings,
    ) {}

    /**
     * @return list<string>
     */
    public function failures(string $password): array
    {
        $failures = [];
        $minimum = $this->settings->passwordMinLength();
        if (mb_strlen($password) < $minimum) {
            $failures[] = 'Password must be at least '.$minimum.' characters.';
        }

        $required = $this->settings->passwordRequiredCharClasses();
        if ($required > 0 && $this->classCount($password) < $required) {
            $failures[] = 'Password must include characters from at least '.$required.' classes.';
        }

        return $failures;
    }

    /**
     * Lower case, upper case, digits, and everything else each count as one class.
     */
    private function classCount(string $password): int
    {
        $count = 0;
        if (preg_match('/[a-z]/', $password) === 1) {
            $count++;
        }
        if (preg_match('/[A-Z]/', $password) === 1) {
            $count++;
        }
        if (preg_match('/[0-9]/', $password) === 1) {
            $count++;
        }
        if (preg_match('/[^a-zA-Z0-9]/', $password) === 1) {
            $count++;
        }

        return $count;
    }
}
