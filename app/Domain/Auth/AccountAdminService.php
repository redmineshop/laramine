<?php

namespace App\Domain\Auth;

use App\Domain\DomainException;
use App\Domain\Notifications\AccountNotifier;
use App\Domain\PermissionDeniedException;
use App\Domain\Queries\QueryVisibility;
use App\Models\AuthSource;
use App\Models\EmailAddress;
use App\Models\User;
use App\Models\UserPreference;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Account administration for an active `users.admin` row.
 *
 * Create, edit, lock, unlock, activate, delete, and group membership.
 * Deleting an account removes personal rows and reassigns public authorship
 * to the AnonymousUser row. Project membership is removed here; `manage_members`
 * stays the project-role path.
 */
final class AccountAdminService
{
    public function __construct(
        private readonly RedminePassword $passwords,
        private readonly PasswordPolicy $policy,
        private readonly AccountNotifier $mail,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(User $actor, array $input): User
    {
        $this->assertAdmin($actor);
        $login = $this->login($input['login'] ?? null, null);
        $firstname = $this->name($input['firstname'] ?? null, 'firstname', 30);
        $lastname = $this->name($input['lastname'] ?? null, 'lastname', 255);
        $mail = $this->mail($input['mail'] ?? null, null);
        $password = $input['password'] ?? null;
        $confirmation = $input['password_confirmation'] ?? $password;
        if (! is_string($password) || ! is_string($confirmation)) {
            throw new AccountValidationException(['password' => ['Password is required.']]);
        }
        $this->assertPassword($password, $confirmation);
        $admin = $this->flag($input['admin'] ?? false, 'admin');
        $notification = $this->notification($input['mail_notification'] ?? 'only_my_events');
        $authSourceId = $this->authSource($input['auth_source_id'] ?? null);
        $status = $this->initialStatus($input['status'] ?? User::STATUS_ACTIVE);
        $mustChange = $this->flag($input['must_change_passwd'] ?? false, 'must_change_passwd');
        $sealed = $this->passwords->seal($password);

        $user = DB::transaction(function () use ($login, $firstname, $lastname, $mail, $admin, $notification, $authSourceId, $status, $mustChange, $sealed): User {
            $user = new User;
            $user->forceFill([
                'login' => $login,
                'hashed_password' => $sealed['hashed_password'],
                'salt' => $sealed['salt'],
                'firstname' => $firstname,
                'lastname' => $lastname,
                'admin' => $admin,
                'status' => $status,
                'type' => User::TYPE_USER,
                'language' => 'en',
                'mail_notification' => $notification,
                'must_change_passwd' => $mustChange,
                'passwd_changed_on' => now()->startOfSecond(),
                'auth_source_id' => $authSourceId,
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

            return $user->refresh();
        });
        $this->mail->information($actor, $user, $password);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(User $actor, User $subject, array $input): User
    {
        $this->assertAdmin($actor);
        $this->assertAccount($subject);
        $changes = [];
        if (array_key_exists('login', $input)) {
            $changes['login'] = $this->login($input['login'], (int) $subject->id);
        }
        if (array_key_exists('firstname', $input)) {
            $changes['firstname'] = $this->name($input['firstname'], 'firstname', 30);
        }
        if (array_key_exists('lastname', $input)) {
            $changes['lastname'] = $this->name($input['lastname'], 'lastname', 255);
        }
        if (array_key_exists('admin', $input)) {
            $admin = $this->flag($input['admin'], 'admin');
            if ($admin === false && (int) $actor->id === (int) $subject->id) {
                throw new DomainException('The current administrator cannot clear their own admin flag.');
            }
            $changes['admin'] = $admin;
        }
        if (array_key_exists('mail_notification', $input)) {
            $changes['mail_notification'] = $this->notification($input['mail_notification']);
        }
        if (array_key_exists('must_change_passwd', $input)) {
            $changes['must_change_passwd'] = $this->flag($input['must_change_passwd'], 'must_change_passwd');
        }
        if (array_key_exists('auth_source_id', $input)) {
            $changes['auth_source_id'] = $this->authSource($input['auth_source_id']);
        }
        if (array_key_exists('password', $input) && $input['password'] !== null && $input['password'] !== '') {
            $password = $input['password'];
            $confirmation = $input['password_confirmation'] ?? $password;
            if (! is_string($password) || ! is_string($confirmation)) {
                throw new AccountValidationException(['password' => ['Password is invalid.']]);
            }
            $this->assertPassword($password, $confirmation);
            $sealed = $this->passwords->seal($password);
            $changes['hashed_password'] = $sealed['hashed_password'];
            $changes['salt'] = $sealed['salt'];
            $changes['passwd_changed_on'] = now()->startOfSecond();
        }

        DB::transaction(function () use ($subject, $input, $changes): void {
            if ($changes !== []) {
                $subject->forceFill($changes)->save();
            }
            if (array_key_exists('mail', $input)) {
                $mail = $this->mail($input['mail'], (int) $subject->id);
                $address = EmailAddress::query()->where('user_id', $subject->id)->where('is_default', true)->first();
                if ($address instanceof EmailAddress) {
                    $address->forceFill(['address' => $mail])->save();
                } else {
                    EmailAddress::query()->create([
                        'user_id' => $subject->id,
                        'address' => $mail,
                        'is_default' => true,
                        'notify' => true,
                    ]);
                }
            }
        });

        return $subject->refresh();
    }

    public function lock(User $actor, User $subject): User
    {
        $this->assertAdmin($actor);
        $this->assertAccount($subject);
        if ((int) $actor->id === (int) $subject->id) {
            throw new DomainException('The current account cannot be locked.');
        }
        if ((int) $subject->status !== User::STATUS_ACTIVE) {
            throw new DomainException('Only an active account can be locked.');
        }
        $subject->forceFill(['status' => User::STATUS_LOCKED])->save();
        $locked = $subject->refresh();
        $this->mail->locked($actor, $locked);

        return $locked;
    }

    public function unlock(User $actor, User $subject): User
    {
        $this->assertAdmin($actor);
        $this->assertAccount($subject);
        if ((int) $subject->status !== User::STATUS_LOCKED) {
            throw new DomainException('Only a locked account can be unlocked.');
        }
        $subject->forceFill(['status' => User::STATUS_ACTIVE])->save();
        $unlocked = $subject->refresh();
        $this->mail->unlocked($actor, $unlocked);

        return $unlocked;
    }

    public function activate(User $actor, User $subject): User
    {
        $this->assertAdmin($actor);
        $this->assertAccount($subject);
        if ((int) $subject->status !== User::STATUS_REGISTERED) {
            throw new DomainException('Only a registered account can be activated.');
        }
        $subject->forceFill(['status' => User::STATUS_ACTIVE])->save();
        $activated = $subject->refresh();
        $this->mail->activated($actor, $activated);

        return $activated;
    }

    public function addToGroup(User $actor, User $user, User $group): void
    {
        $this->assertAdmin($actor);
        $this->assertAccount($user);
        if ($group->type !== User::TYPE_GROUP) {
            throw new DomainException('Group membership needs a group.');
        }
        $exists = DB::table('groups_users')
            ->where('group_id', $group->id)
            ->where('user_id', $user->id)
            ->exists();
        if ($exists) {
            return;
        }
        DB::table('groups_users')->insert([
            'group_id' => $group->id,
            'user_id' => $user->id,
        ]);
    }

    public function removeFromGroup(User $actor, User $user, User $group): void
    {
        $this->assertAdmin($actor);
        DB::table('groups_users')
            ->where('group_id', $group->id)
            ->where('user_id', $user->id)
            ->delete();
    }

    public function delete(User $actor, User $subject): void
    {
        $this->assertAdmin($actor);
        if ($subject->type === User::TYPE_ANONYMOUS) {
            throw new DomainException('The anonymous account cannot be deleted.');
        }
        if ((int) $actor->id === (int) $subject->id) {
            throw new DomainException('The current account cannot be deleted.');
        }

        DB::transaction(function () use ($subject): void {
            $id = (int) $subject->id;
            $anonymousId = $this->anonymousId($id);
            $this->reassignAuthorship($id, $anonymousId);
            $this->deletePersonalRows($id);
            $subject->delete();
        });
    }

    private function anonymousId(int $userId): ?int
    {
        $needs = DB::table('issues')->where('author_id', $userId)->exists()
            || DB::table('journals')->where('user_id', $userId)->exists()
            || DB::table('time_entries')->where('user_id', $userId)->exists()
            || DB::table('attachments')->where('author_id', $userId)->exists()
            || DB::table('comments')->where('author_id', $userId)->exists()
            || DB::table('queries')->where('user_id', $userId)->where('visibility', '!=', QueryVisibility::PRIVATE)->exists();
        if (! $needs) {
            return null;
        }

        $anonymous = User::query()->where('type', User::TYPE_ANONYMOUS)->orderBy('id')->value('id');
        if (! is_numeric($anonymous)) {
            throw new DomainException('Anonymous user is not seeded.');
        }

        return (int) $anonymous;
    }

    private function reassignAuthorship(int $userId, ?int $anonymousId): void
    {
        if ($anonymousId !== null) {
            DB::table('issues')->where('author_id', $userId)->update(['author_id' => $anonymousId]);
            DB::table('journals')->where('user_id', $userId)->update(['user_id' => $anonymousId]);
            DB::table('time_entries')->where('user_id', $userId)->update(['user_id' => $anonymousId]);
            DB::table('attachments')->where('author_id', $userId)->update(['author_id' => $anonymousId]);
            DB::table('comments')->where('author_id', $userId)->update(['author_id' => $anonymousId]);
            DB::table('queries')
                ->where('user_id', $userId)
                ->where('visibility', '!=', QueryVisibility::PRIVATE)
                ->update(['user_id' => $anonymousId]);
        }

        DB::table('issues')->where('assigned_to_id', $userId)->update(['assigned_to_id' => null]);
        DB::table('journals')->where('updated_by_id', $userId)->update(['updated_by_id' => null]);
        DB::table('time_entries')->where('author_id', $userId)->update(['author_id' => null]);
        DB::table('projects')->where('default_assigned_to_id', $userId)->update(['default_assigned_to_id' => null]);
        DB::table('issue_categories')->where('assigned_to_id', $userId)->update(['assigned_to_id' => null]);
        if (Schema::hasTable('changesets')) {
            DB::table('changesets')->where('user_id', $userId)->update(['user_id' => null]);
        }
    }

    private function deletePersonalRows(int $userId): void
    {
        $memberIds = DB::table('members')->where('user_id', $userId)->pluck('id');
        $roleIds = DB::table('member_roles')->whereIn('member_id', $memberIds)->pluck('id');
        if ($roleIds->isNotEmpty()) {
            DB::table('member_roles')->whereIn('inherited_from', $roleIds)->update(['inherited_from' => null]);
            DB::table('member_roles')->whereIn('id', $roleIds)->update(['inherited_from' => null]);
            DB::table('member_roles')->whereIn('id', $roleIds)->delete();
        }
        DB::table('members')->where('user_id', $userId)->delete();

        $privateIds = DB::table('queries')
            ->where('user_id', $userId)
            ->where('visibility', QueryVisibility::PRIVATE)
            ->pluck('id');
        if ($privateIds->isNotEmpty()) {
            DB::table('projects')->whereIn('default_issue_query_id', $privateIds)->update(['default_issue_query_id' => null]);
            DB::table('queries_roles')->whereIn('query_id', $privateIds)->delete();
            DB::table('queries')->whereIn('id', $privateIds)->delete();
        }

        $importIds = DB::table('imports')->where('user_id', $userId)->pluck('id');
        if ($importIds->isNotEmpty()) {
            DB::table('import_items')->whereIn('import_id', $importIds)->delete();
            DB::table('imports')->whereIn('id', $importIds)->delete();
        }

        DB::table('watchers')->where('user_id', $userId)->delete();
        DB::table('reactions')->where('user_id', $userId)->delete();
        DB::table('custom_values')
            ->where('customized_id', $userId)
            ->whereIn('customized_type', ['User', 'Group'])
            ->delete();
        DB::table('email_addresses')->where('user_id', $userId)->delete();
        DB::table('tokens')->where('user_id', $userId)->delete();
        DB::table('user_preferences')->where('user_id', $userId)->delete();
        DB::table('groups_users')->where('user_id', $userId)->orWhere('group_id', $userId)->delete();
        DB::table('oauth_access_grants')->where('resource_owner_id', $userId)->delete();
        DB::table('oauth_access_tokens')->where('resource_owner_id', $userId)->delete();
        DB::table('sessions')->where('user_id', $userId)->delete();
    }

    private function assertAdmin(User $actor): void
    {
        if ($actor->type !== User::TYPE_USER || ! $actor->isActive() || $actor->admin !== true) {
            throw new PermissionDeniedException('admin');
        }
    }

    private function assertAccount(User $subject): void
    {
        if ($subject->type !== User::TYPE_USER) {
            throw new DomainException('This row is not a user account.');
        }
    }

    private function assertPassword(string $password, string $confirmation): void
    {
        $errors = [];
        if ($password !== $confirmation) {
            $errors['password_confirmation'] = ['Password confirmation does not match.'];
        }
        $failures = $this->policy->failures($password);
        if ($failures !== []) {
            $errors['password'] = $failures;
        }
        if ($errors !== []) {
            throw new AccountValidationException($errors);
        }
    }

    private function login(mixed $value, ?int $exceptId): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new AccountValidationException(['login' => ['Login is required.']]);
        }
        $login = trim($value);
        if (mb_strlen($login) > 255) {
            throw new AccountValidationException(['login' => ['Login is too long.']]);
        }
        $query = User::query()->whereRaw('LOWER(login) = LOWER(?)', [$login]);
        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }
        if ($query->exists()) {
            throw new AccountValidationException(['login' => ['Login has already been taken.']]);
        }

        return $login;
    }

    private function name(mixed $value, string $field, int $limit): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new AccountValidationException([$field => [ucfirst($field).' is required.']]);
        }
        $name = trim($value);
        if (mb_strlen($name) > $limit) {
            throw new AccountValidationException([$field => [ucfirst($field).' is too long.']]);
        }

        return $name;
    }

    private function mail(mixed $value, ?int $exceptUserId): string
    {
        if (! is_string($value) || trim($value) === '' || filter_var(trim($value), FILTER_VALIDATE_EMAIL) === false) {
            throw new AccountValidationException(['mail' => ['Mail is invalid.']]);
        }
        $mail = trim($value);
        $query = EmailAddress::query()->whereRaw('LOWER(address) = LOWER(?)', [$mail]);
        if ($exceptUserId !== null) {
            $query->where('user_id', '!=', $exceptUserId);
        }
        if ($query->exists()) {
            throw new AccountValidationException(['mail' => ['Mail has already been taken.']]);
        }

        return $mail;
    }

    private function notification(mixed $value): string
    {
        if (! is_string($value) || ! MailNotification::valid($value)) {
            throw new AccountValidationException([
                'mail_notification' => ['Mail notification must be one of the six values.'],
            ]);
        }

        return $value;
    }

    private function authSource(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value)) {
            throw new AccountValidationException(['auth_source_id' => ['Authentication source is unknown.']]);
        }
        $source = AuthSource::query()->find((int) $value);
        if (! $source instanceof AuthSource || $source->type !== AuthSource::TYPE_LDAP) {
            throw new AccountValidationException(['auth_source_id' => ['Authentication source is unknown.']]);
        }

        return (int) $source->id;
    }

    private function initialStatus(mixed $value): int
    {
        $status = is_numeric($value) ? (int) $value : User::STATUS_ACTIVE;
        if ($status !== User::STATUS_ACTIVE && $status !== User::STATUS_REGISTERED && $status !== User::STATUS_LOCKED) {
            throw new AccountValidationException(['status' => ['Status must be active, registered, or locked.']]);
        }

        return $status;
    }

    private function flag(mixed $value, string $field): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 1 || $value === '1' || $value === 'true' || $value === 'on') {
            return true;
        }
        if ($value === 0 || $value === '0' || $value === 'false' || $value === '') {
            return false;
        }

        throw new AccountValidationException([$field => ['The value must be true or false.']]);
    }
}
