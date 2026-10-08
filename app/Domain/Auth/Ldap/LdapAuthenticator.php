<?php

namespace App\Domain\Auth\Ldap;

use App\Domain\Auth\LoginDecision;
use App\Domain\Auth\RedminePassword;
use App\Domain\Settings\SettingValue;
use App\Models\AuthSource;
use App\Models\EmailAddress;
use App\Models\User;
use App\Models\UserPreference;
use Illuminate\Support\Facades\DB;

/**
 * LDAP sign-in for `auth_sources.type = AuthSourceLdap`.
 *
 * A user with `auth_source_id` is checked against that source. The local
 * digest is not the credential. On-the-fly registration creates an active
 * account from the mapped attributes when the source allows it.
 */
final class LdapAuthenticator
{
    /**
     * How many "add user from LDAP" rows one search returns.
     */
    public const SEARCH_LIMIT = 10;

    private const LOGIN_LIMIT = 60;

    private const MAIL_LIMIT = 254;

    public function __construct(
        private readonly LdapDirectory $directory,
        private readonly RedminePassword $passwords,
        private readonly SettingValue $settings,
    ) {}

    public function provision(string $identifier, string $password): void
    {
        $identifier = trim($identifier);
        if ($identifier === '' || $password === '') {
            return;
        }

        $sources = AuthSource::query()
            ->where('type', AuthSource::TYPE_LDAP)
            ->where('onthefly_register', true)
            ->orderBy('id')
            ->get();

        foreach ($sources as $source) {
            $check = $this->bind($source, $identifier, $password);
            if ($check instanceof LdapEntry) {
                $this->createUser($source, $check);

                return;
            }
        }
    }

    public function check(User $user, string $password): LoginDecision
    {
        $source = $user->authSource;
        if (! $source instanceof AuthSource || $source->type !== AuthSource::TYPE_LDAP) {
            return LoginDecision::ExternalAuth;
        }

        $login = trim((string) $user->login);
        $check = $this->bind($source, $login, $password);
        if ($check instanceof LoginDecision) {
            return $check;
        }

        $this->syncAttributes($user, $source, $check);

        return LoginDecision::Accepted;
    }

    public function filter(AuthSource $source, string $login): string
    {
        $loginClause = '('.$this->attribute($source->attr_login, 'uid').'='.$this->escape($login).')';
        $extra = trim((string) $source->filter);
        if ($extra === '') {
            return $loginClause;
        }
        if (! str_starts_with($extra, '(')) {
            $extra = '('.$extra.')';
        }

        return '(&'.$loginClause.$extra.')';
    }

    /**
     * Prefix search used when an administrator adds an account from the directory.
     *
     * A blank query, a blank host, an account containing `$login`, or a blank
     * mapped attribute returns no rows. At most {@see SEARCH_LIMIT} rows are returned.
     *
     * @return list<LdapUserMatch>
     */
    public function searchUsers(AuthSource $source, string $query): array
    {
        $query = trim($query);
        if ($query === '' || trim((string) $source->host) === '' || ! $this->searchable($source)) {
            return [];
        }
        $entries = $this->directory->search($source, $this->userFilter($source, $query), null, null, self::SEARCH_LIMIT);
        $matches = [];
        foreach ($entries as $entry) {
            $login = $entry->first((string) $source->attr_login);
            if ($login === '') {
                continue;
            }
            $matches[] = new LdapUserMatch(
                $login,
                $entry->first((string) $source->attr_firstname),
                $entry->first((string) $source->attr_lastname),
                $entry->first((string) $source->attr_mail),
                $entry->dn,
            );
            if (count($matches) >= self::SEARCH_LIMIT) {
                break;
            }
        }

        return $matches;
    }

    public function userFilter(AuthSource $source, string $query): string
    {
        $loginAttr = trim((string) $source->attr_login);
        $prefix = '('.$loginAttr.'='.$this->escape($query).'*)';
        $extra = trim((string) $source->filter);
        if ($extra === '') {
            return '(&(objectClass=*)'.$prefix.')';
        }
        if (! str_starts_with($extra, '(')) {
            $extra = '('.$extra.')';
        }

        return '(&(objectClass=*)'.$prefix.$extra.')';
    }

    public function testConnection(AuthSource $source): void
    {
        if (trim((string) $source->host) === '') {
            throw new LdapBindException('LDAP host is blank.');
        }
        $this->directory->testConnection($source);
    }

    private function bind(AuthSource $source, string $login, string $password): LdapEntry|LoginDecision
    {
        if ($password === '') {
            return LoginDecision::Password;
        }
        if (trim((string) $source->host) === '') {
            return LoginDecision::ExternalAuth;
        }

        try {
            $entries = $this->directory->search($source, $this->filter($source, $login), $login, $password);
        } catch (LdapBindException) {
            return LoginDecision::ExternalAuth;
        }

        if (count($entries) !== 1) {
            return LoginDecision::Password;
        }

        $entry = $entries[0];
        try {
            $accepted = $this->directory->authenticate($source, $entry->dn, $password);
        } catch (LdapBindException) {
            return LoginDecision::ExternalAuth;
        }

        return $accepted ? $entry : LoginDecision::Password;
    }

    private function createUser(AuthSource $source, LdapEntry $entry): void
    {
        $login = $entry->first($this->attribute($source->attr_login, 'uid'));
        $firstname = $entry->first($this->attribute($source->attr_firstname, 'givenName'));
        $lastname = $entry->first($this->attribute($source->attr_lastname, 'sn'));
        $mail = $entry->first($this->attribute($source->attr_mail, 'mail'));
        if (! $this->onTheFlyAttributes($login, $firstname, $lastname, $mail)) {
            return;
        }
        if ($this->loginTaken($login) || $this->mailTaken($mail)) {
            return;
        }

        $sealed = $this->passwords->seal(bin2hex(random_bytes(16)));
        DB::transaction(function () use ($source, $login, $firstname, $lastname, $mail, $sealed): void {
            $user = new User;
            $user->forceFill([
                'login' => $login,
                'hashed_password' => $sealed['hashed_password'],
                'salt' => $sealed['salt'],
                'firstname' => $firstname,
                'lastname' => $lastname,
                'admin' => false,
                'status' => User::STATUS_ACTIVE,
                'type' => User::TYPE_USER,
                'language' => 'en',
                'mail_notification' => $this->settings->defaultMailNotification(),
                'must_change_passwd' => false,
                'auth_source_id' => $source->id,
            ])->save();

            EmailAddress::query()->create([
                'user_id' => $user->id,
                'address' => $mail,
                'is_default' => true,
                'notify' => true,
            ]);
            UserPreference::query()->create([
                'user_id' => $user->id,
                'hide_mail' => true,
            ]);
        });
    }

    private function syncAttributes(User $user, AuthSource $source, LdapEntry $entry): void
    {
        $firstname = mb_substr($entry->first($this->attribute($source->attr_firstname, 'givenName')), 0, 30);
        $lastname = mb_substr($entry->first($this->attribute($source->attr_lastname, 'sn')), 0, 255);
        $mail = $entry->first($this->attribute($source->attr_mail, 'mail'));
        $changes = [];
        if ($firstname !== '') {
            $changes['firstname'] = $firstname;
        }
        if ($lastname !== '') {
            $changes['lastname'] = $lastname;
        }
        if ($changes !== []) {
            $user->forceFill($changes)->save();
        }
        if ($mail === '' || $this->mailTaken($mail, (int) $user->id)) {
            return;
        }

        $address = EmailAddress::query()
            ->where('user_id', $user->id)
            ->where('is_default', true)
            ->first();
        if ($address instanceof EmailAddress) {
            $address->forceFill(['address' => $mail])->save();

            return;
        }

        EmailAddress::query()->create([
            'user_id' => $user->id,
            'address' => $mail,
            'is_default' => true,
            'notify' => true,
        ]);
    }

    private function searchable(AuthSource $source): bool
    {
        if (str_contains((string) $source->account, '$login')) {
            return false;
        }
        foreach ([$source->attr_login, $source->attr_firstname, $source->attr_lastname, $source->attr_mail] as $name) {
            if (trim((string) $name) === '') {
                return false;
            }
        }

        return true;
    }

    private function onTheFlyAttributes(string $login, string $firstname, string $lastname, string $mail): bool
    {
        if ($login === '' || $firstname === '' || $lastname === '' || $mail === '') {
            return false;
        }
        if (mb_strlen($login) > self::LOGIN_LIMIT || preg_match('/\A[A-Za-z0-9_\-@.]*\z/', $login) !== 1) {
            return false;
        }
        if (mb_strlen($firstname) > 30 || mb_strlen($lastname) > 255 || mb_strlen($mail) > self::MAIL_LIMIT) {
            return false;
        }

        return filter_var($mail, FILTER_VALIDATE_EMAIL) !== false;
    }

    private function attribute(?string $stored, string $default): string
    {
        $name = trim((string) $stored);
        if ($name === '' || preg_match('/^[A-Za-z][A-Za-z0-9-]*$/', $name) !== 1) {
            return $default;
        }

        return $name;
    }

    private function escape(string $value): string
    {
        return str_replace(
            ['\\', '*', '(', ')', "\x00"],
            ['\\5c', '\\2a', '\\28', '\\29', '\\00'],
            $value,
        );
    }

    private function loginTaken(string $login): bool
    {
        return User::query()->whereRaw('LOWER(login) = LOWER(?)', [$login])->exists();
    }

    private function mailTaken(string $mail, ?int $exceptUserId = null): bool
    {
        $query = EmailAddress::query()->whereRaw('LOWER(address) = LOWER(?)', [$mail]);
        if ($exceptUserId !== null) {
            $query->where('user_id', '!=', $exceptUserId);
        }

        return $query->exists();
    }
}
