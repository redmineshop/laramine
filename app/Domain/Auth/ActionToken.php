<?php

namespace App\Domain\Auth;

use App\Models\Token;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Issues and reads `tokens` rows.
 *
 * `recovery` and `register` keep one row and expire after one day.
 * `api` and `feeds` keep one row and do not expire. `session`, `autologin`,
 * and `twofa_backup_code` keep at most ten rows. The value is 40 lowercase
 * hex characters.
 */
final class ActionToken
{
    public const LIFETIME_SECONDS = 86400;

    public function issue(User $user, string $action): Token
    {
        if ($action !== Token::ACTION_RECOVERY && $action !== Token::ACTION_REGISTER) {
            throw new InvalidArgumentException('Only recovery and register tokens are issued.');
        }

        return $this->issueNamed($user, $action);
    }

    public function issueNamed(User $user, string $action): Token
    {
        $this->trim($user, $action, $this->maximum($action) - 1);

        $created = null;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $created = Token::query()->create([
                    'user_id' => $user->id,
                    'action' => $action,
                    'value' => bin2hex(random_bytes(20)),
                ]);
                break;
            } catch (UniqueConstraintViolationException) {
                $created = null;
            }
        }

        if (! $created instanceof Token) {
            throw new InvalidArgumentException('Could not store a unique token value.');
        }

        return $created;
    }

    /**
     * @return list<string>
     */
    public function replaceBackupCodes(User $user, int $count): array
    {
        Token::query()
            ->where('user_id', $user->id)
            ->where('action', Token::ACTION_TWOFA_BACKUP)
            ->delete();

        $values = [];
        for ($index = 0; $index < $count; $index++) {
            $values[] = $this->issueNamed($user, Token::ACTION_TWOFA_BACKUP)->value;
        }

        return $values;
    }

    public function findByValue(string $action, string $value): ?Token
    {
        $this->maximum($action);
        if ($value === '' || strlen($value) !== 40) {
            return null;
        }

        $token = Token::query()
            ->where('action', $action)
            ->where('value', $value)
            ->first();

        return $token instanceof Token ? $token : null;
    }

    public function findUsable(string $action, string $value): ?Token
    {
        $this->assertAction($action);
        if ($value === '' || strlen($value) !== 40) {
            return null;
        }

        $token = Token::query()
            ->where('action', $action)
            ->where('value', $value)
            ->first();

        if (! $token instanceof Token || $this->isExpired($token)) {
            return null;
        }

        return $token;
    }

    public function consume(Token $token): void
    {
        $token->delete();
    }

    public function isExpired(Token $token): bool
    {
        $created = $token->created_on;
        if (! $created instanceof Carbon) {
            return true;
        }

        return $created->lessThanOrEqualTo(now()->subSeconds(self::LIFETIME_SECONDS));
    }

    private function assertAction(string $action): void
    {
        if ($action !== Token::ACTION_RECOVERY && $action !== Token::ACTION_REGISTER) {
            throw new InvalidArgumentException('Only recovery and register tokens are issued.');
        }
    }

    private function maximum(string $action): int
    {
        return match ($action) {
            Token::ACTION_API, Token::ACTION_FEEDS, Token::ACTION_RECOVERY, Token::ACTION_REGISTER => 1,
            Token::ACTION_SESSION, Token::ACTION_AUTOLOGIN, Token::ACTION_TWOFA_BACKUP => 10,
            default => throw new InvalidArgumentException('Unknown token action.'),
        };
    }

    private function trim(User $user, string $action, int $keep): void
    {
        $existing = Token::query()
            ->where('user_id', $user->id)
            ->where('action', $action)
            ->orderBy('created_on')
            ->orderBy('id')
            ->get();
        $overflow = $existing->count() - $keep;
        if ($overflow <= 0) {
            return;
        }

        foreach ($existing->take($overflow) as $row) {
            $row->delete();
        }
    }
}
