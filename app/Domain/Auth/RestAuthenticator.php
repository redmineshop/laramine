<?php

namespace App\Domain\Auth;

use App\Domain\Auth\Oauth\OauthProvider;
use App\Domain\Settings\SettingValue;
use App\Models\Token;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * REST authentication for `X-Redmine-API-Key`, the `key` parameter, OAuth bearer, and HTTP basic.
 *
 * Feed keys are a different token action and do not authenticate this path.
 * `X-Redmine-Switch-User` is accepted only for an active administrator.
 */
final class RestAuthenticator
{
    public function __construct(
        private readonly SettingValue $settings,
        private readonly ActionToken $tokens,
        private readonly CredentialChecker $checker,
        private readonly TwoFactorPolicy $twoFactor,
        private readonly OauthProvider $oauth,
    ) {}

    public function authenticate(Request $request): RestIdentity
    {
        if (! $this->settings->restApiEnabled()) {
            return new RestIdentity(null, 401);
        }

        $user = $this->fromApiKey($request) ?? $this->fromBearer($request) ?? $this->fromBasic($request);
        if (! $user instanceof User) {
            return new RestIdentity(null, 401);
        }

        return $this->switchUser($request, $user);
    }

    public function feedUser(Request $request): ?User
    {
        $key = $request->query('key');
        if (is_string($key) && $key !== '') {
            return $this->active($this->tokens->findByValue(Token::ACTION_FEEDS, $key)?->user);
        }

        return $this->active($request->user());
    }

    private function fromApiKey(Request $request): ?User
    {
        $header = $request->header('X-Redmine-API-Key');
        $key = is_string($header) && $header !== '' ? $header : $request->query('key');
        if (! is_string($key) || $key === '') {
            return null;
        }

        return $this->active($this->tokens->findByValue(Token::ACTION_API, $key)?->user);
    }

    private function fromBearer(Request $request): ?User
    {
        $header = $request->header('Authorization');
        if (! is_string($header) || ! str_starts_with($header, 'Bearer ')) {
            return null;
        }

        return $this->active($this->oauth->resourceOwner(trim(substr($header, 7))));
    }

    private function fromBasic(Request $request): ?User
    {
        $login = $request->getUser();
        $password = $request->getPassword();
        if (! is_string($login) || $login === '') {
            return null;
        }

        $byKey = $this->active($this->tokens->findByValue(Token::ACTION_API, $login)?->user);
        if ($byKey instanceof User) {
            return $byKey;
        }

        if (! is_string($password) || $password === '') {
            return null;
        }

        if ($this->checker->decide($login, $password) !== LoginDecision::Accepted) {
            return null;
        }

        $user = $this->checker->findByIdentifier($login);
        if (! $user instanceof User || $this->twoFactor->challengeRequired($user)) {
            return null;
        }

        return $this->active($user);
    }

    private function switchUser(Request $request, User $actor): RestIdentity
    {
        $header = $request->header('X-Redmine-Switch-User');
        if (! is_string($header) || $header === '') {
            return new RestIdentity($actor, 200);
        }
        if ($actor->admin !== true) {
            return new RestIdentity(null, 412);
        }

        $target = User::query()
            ->where('type', User::TYPE_USER)
            ->whereRaw('LOWER(login) = LOWER(?)', [trim($header)])
            ->first();
        if (! $target instanceof User || ! $target->canKeepWebSession()) {
            return new RestIdentity(null, 412);
        }

        return new RestIdentity($target, 200);
    }

    private function active(mixed $user): ?User
    {
        return $user instanceof User && $user->canKeepWebSession() ? $user : null;
    }
}
