<?php

namespace App\Domain\Auth;

use App\Models\Token;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Issues and reads `tokens` rows for the recovery and register actions.
 *
 * Each account keeps one row per action. A new issue deletes the previous
 * row. The value is 40 lowercase hex characters. A row is expired when
 * `created_on` is at least one day old.
 */
final class ActionToken
{
    public const LIFETIME_SECONDS = 86400;

    public function issue(User $user, string $action): Token
    {
        $this->assertAction($action);

        Token::query()
            ->where('user_id', $user->id)
            ->where('action', $action)
            ->delete();

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
}
