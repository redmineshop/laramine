<?php

namespace App\Domain\Auth\Oauth;

use App\Domain\PermissionDeniedException;
use App\Models\OauthAccessGrant;
use App\Models\OauthAccessToken;
use App\Models\OauthApplication;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * OAuth 2 authorization-code server on the Doorkeeper-shaped tables.
 *
 * Redmine 7.0.1 core ships these tables and PKCE columns. It does not ship
 * an OpenID Connect discovery document, an id_token, or an external identity
 * provider login. Those are not implemented here.
 */
final class OauthProvider
{
    public const CODE_TTL = 600;

    public const TOKEN_TTL = 7200;

    /**
     * @return array{application: OauthApplication, secret: string}
     */
    public function registerApplication(User $actor, string $name, string $redirectUri, string $scopes, bool $confidential): array
    {
        $this->assertAdmin($actor);
        $name = trim($name);
        $redirects = $this->splitRedirects($redirectUri);
        if ($name === '' || $redirects === []) {
            throw new OauthException('invalid_request', 'Application name and redirect URI are required.');
        }
        foreach ($redirects as $uri) {
            $this->assertAbsoluteUri($uri);
        }
        $scope = $this->normalizeScope($scopes, $scopes);

        $secret = bin2hex(random_bytes(32));
        $application = OauthApplication::query()->create([
            'name' => $name,
            'uid' => bin2hex(random_bytes(16)),
            'secret' => $secret,
            'redirect_uri' => implode("\n", $redirects),
            'scopes' => $scope,
            'confidential' => $confidential,
        ]);

        return [
            'application' => $application,
            'secret' => $secret,
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     */
    public function authorize(User $owner, array $query, bool $approved): string
    {
        if (! $owner->canKeepWebSession()) {
            throw new OauthException('access_denied', 'The resource owner is not an active account.');
        }

        $application = $this->applicationByUid($this->string($query, 'client_id'));
        $redirect = $this->string($query, 'redirect_uri');
        $this->assertRedirect($application, $redirect);
        if ($this->string($query, 'response_type') !== 'code') {
            return $this->errorRedirect($redirect, 'unsupported_response_type', $this->optional($query, 'state'));
        }
        if (! $approved) {
            return $this->errorRedirect($redirect, 'access_denied', $this->optional($query, 'state'));
        }

        $method = $this->optional($query, 'code_challenge_method');
        $challenge = $this->optional($query, 'code_challenge');
        if (! $application->confidential && ($challenge === null || $method === null)) {
            return $this->errorRedirect($redirect, 'invalid_request', $this->optional($query, 'state'));
        }
        if ($challenge !== null && $method !== 'S256' && $method !== 'plain') {
            return $this->errorRedirect($redirect, 'invalid_request', $this->optional($query, 'state'));
        }

        try {
            $scope = $this->normalizeScope($this->optional($query, 'scope') ?? '', (string) $application->scopes);
        } catch (OauthException) {
            return $this->errorRedirect($redirect, 'invalid_scope', $this->optional($query, 'state'));
        }

        $code = bin2hex(random_bytes(32));
        OauthAccessGrant::query()->create([
            'application_id' => $application->id,
            'resource_owner_id' => $owner->id,
            'token' => $code,
            'expires_in' => self::CODE_TTL,
            'redirect_uri' => $redirect,
            'scopes' => $scope,
            'code_challenge' => $challenge,
            'code_challenge_method' => $method,
        ]);

        return $this->successRedirect($redirect, $code, $this->optional($query, 'state'));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{access_token: string, token_type: string, expires_in: int, refresh_token: string, scope: string}
     */
    public function token(array $input): array
    {
        $grantType = $this->string($input, 'grant_type');
        $application = $this->authenticateClient($input);

        if ($grantType === 'authorization_code') {
            return $this->exchangeCode($application, $input);
        }
        if ($grantType === 'refresh_token') {
            return $this->refresh($application, $input);
        }

        throw new OauthException('unsupported_grant_type');
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function revoke(array $input): void
    {
        $application = $this->authenticateClient($input);
        $presented = $this->string($input, 'token');
        $row = OauthAccessToken::query()
            ->where('application_id', $application->id)
            ->where(function (Builder $query) use ($presented): void {
                $query->where('token', $presented)->orWhere('refresh_token', $presented);
            })
            ->first();
        if ($row instanceof OauthAccessToken && $row->revoked_at === null) {
            $row->forceFill(['revoked_at' => now()])->save();
        }
    }

    public function resourceOwner(string $bearer): ?User
    {
        $row = $this->acceptedToken($bearer);
        if (! $row instanceof OauthAccessToken) {
            return null;
        }

        $user = $row->resourceOwner;

        return $user instanceof User && $user->canKeepWebSession() ? $user : null;
    }

    /**
     * A bearer row that is still usable: present, not revoked, and not expired.
     */
    public function acceptedToken(string $bearer): ?OauthAccessToken
    {
        if ($bearer === '') {
            return null;
        }

        $row = OauthAccessToken::query()->where('token', $bearer)->first();
        if (! $row instanceof OauthAccessToken || $row->revoked_at !== null || $this->expired($row->created_at, $row->expires_in)) {
            return null;
        }

        $user = $row->resourceOwner;
        if (! $user instanceof User || ! $user->canKeepWebSession()) {
            return null;
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{access_token: string, token_type: string, expires_in: int, refresh_token: string, scope: string}
     */
    private function exchangeCode(OauthApplication $application, array $input): array
    {
        $code = $this->string($input, 'code');
        $redirect = $this->string($input, 'redirect_uri');
        $grant = OauthAccessGrant::query()
            ->where('token', $code)
            ->where('application_id', $application->id)
            ->first();
        if (! $grant instanceof OauthAccessGrant
            || $grant->revoked_at !== null
            || $grant->redirect_uri !== $redirect
            || $this->expired($grant->created_at, (int) $grant->expires_in)) {
            throw new OauthException('invalid_grant');
        }

        $verifier = $this->optional($input, 'code_verifier');
        if (! Pkce::verify($grant->code_challenge_method, $grant->code_challenge, $verifier)) {
            throw new OauthException('invalid_grant');
        }

        $grant->forceFill(['revoked_at' => now()])->save();
        $owner = $grant->resourceOwner;
        if (! $owner instanceof User || ! $owner->canKeepWebSession()) {
            throw new OauthException('invalid_grant');
        }

        return $this->issueToken($application, $owner, (string) $grant->scopes);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{access_token: string, token_type: string, expires_in: int, refresh_token: string, scope: string}
     */
    private function refresh(OauthApplication $application, array $input): array
    {
        $presented = $this->string($input, 'refresh_token');
        $current = OauthAccessToken::query()
            ->where('application_id', $application->id)
            ->where('refresh_token', $presented)
            ->first();
        if ($current instanceof OauthAccessToken && $current->revoked_at === null) {
            $owner = $current->resourceOwner;
            if (! $owner instanceof User || ! $owner->canKeepWebSession()) {
                throw new OauthException('invalid_grant');
            }
            $current->forceFill(['revoked_at' => now()])->save();
            $issued = $this->issueToken($application, $owner, (string) $current->scopes);
            $replacement = OauthAccessToken::query()->where('token', $issued['access_token'])->first();
            if ($replacement instanceof OauthAccessToken) {
                $replacement->forceFill(['previous_refresh_token' => $presented])->save();
            }

            return $issued;
        }

        $reused = OauthAccessToken::query()
            ->where('application_id', $application->id)
            ->where('previous_refresh_token', $presented)
            ->first();
        if ($reused instanceof OauthAccessToken && $reused->revoked_at === null) {
            $reused->forceFill(['revoked_at' => now()])->save();
        }

        throw new OauthException('invalid_grant');
    }

    /**
     * @return array{access_token: string, token_type: string, expires_in: int, refresh_token: string, scope: string}
     */
    private function issueToken(OauthApplication $application, User $owner, string $scope): array
    {
        $access = bin2hex(random_bytes(32));
        $refresh = bin2hex(random_bytes(32));
        OauthAccessToken::query()->create([
            'application_id' => $application->id,
            'resource_owner_id' => $owner->id,
            'token' => $access,
            'refresh_token' => $refresh,
            'expires_in' => self::TOKEN_TTL,
            'scopes' => $scope,
            'previous_refresh_token' => '',
        ]);

        return [
            'access_token' => $access,
            'token_type' => 'Bearer',
            'expires_in' => self::TOKEN_TTL,
            'refresh_token' => $refresh,
            'scope' => $scope,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function authenticateClient(array $input): OauthApplication
    {
        $application = $this->applicationByUid($this->string($input, 'client_id'));
        if (! $application->confidential) {
            return $application;
        }

        $secret = $this->optional($input, 'client_secret') ?? '';
        $stored = (string) $application->secret;
        if ($secret === '' || strlen($secret) !== strlen($stored) || ! hash_equals($stored, $secret)) {
            throw new OauthException('invalid_client');
        }

        return $application;
    }

    private function applicationByUid(string $uid): OauthApplication
    {
        $application = OauthApplication::query()->where('uid', $uid)->first();
        if (! $application instanceof OauthApplication) {
            throw new OauthException('invalid_client');
        }

        return $application;
    }

    private function assertAdmin(User $actor): void
    {
        if ($actor->type !== User::TYPE_USER || ! $actor->isActive() || $actor->admin !== true) {
            throw new PermissionDeniedException('admin');
        }
    }

    private function assertRedirect(OauthApplication $application, string $redirect): void
    {
        $this->assertAbsoluteUri($redirect);
        if (! in_array($redirect, $this->splitRedirects((string) $application->redirect_uri), true)) {
            throw new OauthException('invalid_request', 'Redirect URI does not match the application.');
        }
    }

    private function assertAbsoluteUri(string $uri): void
    {
        if (preg_match('#\Ahttps?://[^\s]+#', $uri) !== 1) {
            throw new OauthException('invalid_request', 'Redirect URI must be an absolute http(s) URI.');
        }
    }

    /**
     * @return list<string>
     */
    private function splitRedirects(string $stored): array
    {
        $parts = preg_split('/\s+/', trim($stored));
        if ($parts === false) {
            return [];
        }

        $uris = [];
        foreach ($parts as $part) {
            if ($part !== '') {
                $uris[] = $part;
            }
        }

        return $uris;
    }

    private function normalizeScope(string $requested, string $allowed): string
    {
        $requestedParts = $this->scopeParts($requested);
        foreach ($requestedParts as $part) {
            if (preg_match('/^[A-Za-z0-9_.:\-]+$/', $part) !== 1) {
                throw new OauthException('invalid_scope');
            }
        }

        $allowedParts = $this->scopeParts($allowed);
        if ($allowedParts === []) {
            return implode(' ', $requestedParts);
        }
        foreach ($requestedParts as $part) {
            if (! in_array($part, $allowedParts, true)) {
                throw new OauthException('invalid_scope');
            }
        }

        return implode(' ', $requestedParts);
    }

    /**
     * @return list<string>
     */
    private function scopeParts(string $scope): array
    {
        $scope = trim($scope);
        if ($scope === '') {
            return [];
        }
        $parts = preg_split('/\s+/', $scope);
        if ($parts === false) {
            return [];
        }

        return array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    private function expired(mixed $created, mixed $expiresIn): bool
    {
        if (! $created instanceof Carbon) {
            return true;
        }
        if ($expiresIn === null || $expiresIn === '') {
            return false;
        }
        if (! is_numeric($expiresIn)) {
            return true;
        }

        return $created->getTimestamp() + (int) $expiresIn <= now()->getTimestamp();
    }

    private function successRedirect(string $redirect, string $code, ?string $state): string
    {
        $query = ['code' => $code];
        if ($state !== null && $state !== '') {
            $query['state'] = $state;
        }

        return $this->withQuery($redirect, $query);
    }

    private function errorRedirect(string $redirect, string $error, ?string $state): string
    {
        $query = ['error' => $error];
        if ($state !== null && $state !== '') {
            $query['state'] = $state;
        }

        return $this->withQuery($redirect, $query);
    }

    /**
     * @param  array<string, string>  $query
     */
    private function withQuery(string $redirect, array $query): string
    {
        $separator = str_contains($redirect, '?') ? '&' : '?';

        return $redirect.$separator.http_build_query($query);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function string(array $input, string $key): string
    {
        $value = $input[$key] ?? null;
        if (! is_string($value) || $value === '') {
            throw new OauthException('invalid_request', $key.' is required.');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function optional(array $input, string $key): ?string
    {
        $value = $input[$key] ?? null;
        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }
}
