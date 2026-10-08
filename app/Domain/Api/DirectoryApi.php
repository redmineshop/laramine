<?php

namespace App\Domain\Api;

use App\Domain\Acl\PermissionService;
use App\Domain\Acl\UserVisibility;
use App\Domain\Auth\AccountAdminService;
use App\Domain\Auth\OauthScope;
use App\Domain\DomainException;
use App\Http\Api\ApiCall;
use App\Http\Api\ApiLocation;
use App\Http\Api\ApiPage;
use App\Http\Api\ApiQuery;
use App\Http\Api\ApiResult;
use App\Models\EmailAddress;
use App\Models\Token;
use App\Models\User;
use App\Models\UserPreference;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Users, groups, and the signed-in account for the REST API.
 */
final class DirectoryApi
{
    public function __construct(
        private readonly ApiCall $calls,
        private readonly ApiValues $values,
        private readonly PermissionService $permissions,
        private readonly UserVisibility $visibility,
        private readonly AccountAdminService $accounts,
        private readonly OauthScope $oauthScope,
    ) {}

    public function users(User $actor, Request $request): ApiResult
    {
        return $this->calls->run(function () use ($actor, $request): ApiResult {
            if (! $this->admin($actor)) {
                return ApiResult::fail(403, 'You are not authorized to access this page.');
            }
            $page = ApiPage::from($request);
            $query = User::query()->where('type', User::TYPE_USER)->orderBy('lastname')->orderBy('firstname')->orderBy('id');
            $status = $request->query('status', '1');
            if ($status !== '*') {
                $query->where('status', is_numeric($status) ? (int) $status : User::STATUS_ACTIVE);
            }
            $name = $request->query('name');
            if (is_string($name) && $name !== '') {
                $query->where(function (Builder $inner) use ($name): void {
                    $inner->where('login', 'like', '%'.$name.'%')
                        ->orWhere('firstname', 'like', '%'.$name.'%')
                        ->orWhere('lastname', 'like', '%'.$name.'%');
                });
            }
            $users = $query->get()->all();
            $slice = $page->slice($users);
            $rows = [];
            foreach ($slice['rows'] as $user) {
                $rows[] = $this->summary($actor, $user);
            }

            return ApiResult::ok($this->calls->collection('users', $rows, $page, $slice['total']));
        });
    }

    public function showUser(User $actor, string $key): ApiResult
    {
        return $this->calls->run(function () use ($actor, $key): ApiResult {
            $user = $this->findUser($actor, $key);
            if ($user instanceof ApiResult) {
                return $user;
            }

            return ApiResult::ok(['user' => $this->detail($actor, $user)]);
        });
    }

    public function storeUser(User $actor, Request $request): ApiResult
    {
        return $this->calls->run(function () use ($actor, $request): ApiResult {
            $attributes = ApiQuery::resource($request, 'user');
            if (! array_key_exists('password_confirmation', $attributes) && array_key_exists('password', $attributes)) {
                $attributes['password_confirmation'] = $attributes['password'];
            }
            $user = $this->accounts->create($actor, $attributes);

            return ApiResult::created(
                ['user' => $this->detail($actor, $user->refresh())],
                ApiLocation::to($request, 'users/'.$user->id),
            );
        });
    }

    public function updateUser(User $actor, int $id, Request $request): ApiResult
    {
        return $this->calls->run(function () use ($actor, $id, $request): ApiResult {
            $user = User::query()->where('type', User::TYPE_USER)->find($id);
            if (! $user instanceof User) {
                return ApiResult::fail(404, 'Not found');
            }
            $attributes = ApiQuery::resource($request, 'user');
            if (! array_key_exists('password_confirmation', $attributes) && array_key_exists('password', $attributes)) {
                $attributes['password_confirmation'] = $attributes['password'];
            }
            $this->accounts->update($actor, $user, $attributes);

            return ApiResult::noContent();
        });
    }

    public function destroyUser(User $actor, int $id): ApiResult
    {
        return $this->calls->run(function () use ($actor, $id): ApiResult {
            $user = User::query()->where('type', User::TYPE_USER)->find($id);
            if (! $user instanceof User) {
                return ApiResult::fail(404, 'Not found');
            }
            $this->accounts->delete($actor, $user);

            return ApiResult::noContent();
        });
    }

    public function account(User $actor): ApiResult
    {
        return ApiResult::ok(['user' => $this->detail($actor, $actor)]);
    }

    public function updateAccount(User $actor, Request $request): ApiResult
    {
        return $this->calls->run(function () use ($actor, $request): ApiResult {
            $attributes = ApiQuery::resource($request, 'user');
            if (array_key_exists('firstname', $attributes)) {
                $actor->firstname = $this->requiredName($attributes['firstname'], 'First name');
            }
            if (array_key_exists('lastname', $attributes)) {
                $actor->lastname = $this->requiredName($attributes['lastname'], 'Last name');
            }
            $actor->save();
            if (array_key_exists('mail', $attributes)) {
                $mail = trim($this->values->text($attributes['mail']));
                if ($mail === '' || filter_var($mail, FILTER_VALIDATE_EMAIL) === false) {
                    throw new DomainException('Mail is not valid.');
                }
                $address = EmailAddress::query()->where('user_id', $actor->id)->where('is_default', true)->first();
                if ($address instanceof EmailAddress) {
                    $address->address = $mail;
                    $address->save();
                } else {
                    EmailAddress::query()->create([
                        'user_id' => $actor->id,
                        'address' => $mail,
                        'is_default' => true,
                        'notify' => true,
                    ]);
                }
            }

            return ApiResult::noContent();
        });
    }

    public function groups(User $actor, Request $request): ApiResult
    {
        return $this->calls->run(function () use ($actor, $request): ApiResult {
            if (! $this->admin($actor)) {
                return ApiResult::fail(403, 'You are not authorized to access this page.');
            }
            $page = ApiPage::from($request);
            $groups = User::query()->where('type', User::TYPE_GROUP)->orderBy('lastname')->orderBy('id')->get()->all();
            $slice = $page->slice($groups);
            $rows = [];
            foreach ($slice['rows'] as $group) {
                $rows[] = $this->groupDocument($actor, $group, ApiQuery::includes($request));
            }

            return ApiResult::ok($this->calls->collection('groups', $rows, $page, $slice['total']));
        });
    }

    public function showGroup(User $actor, int $id, Request $request): ApiResult
    {
        return $this->calls->run(function () use ($actor, $id, $request): ApiResult {
            $group = $this->readableGroup($actor, $id);
            if ($group instanceof ApiResult) {
                return $group;
            }

            return ApiResult::ok(['group' => $this->groupDocument($actor, $group, ApiQuery::includes($request))]);
        });
    }

    public function storeGroup(User $actor, Request $request): ApiResult
    {
        return $this->calls->run(function () use ($actor, $request): ApiResult {
            if (! $this->admin($actor)) {
                return ApiResult::fail(403, 'You are not authorized to access this page.');
            }
            $attributes = ApiQuery::resource($request, 'group');
            $name = trim($this->values->text($attributes['name'] ?? null));
            if ($name === '') {
                throw new DomainException('Group name is required.');
            }
            $login = Str::slug($name, '_');
            if ($login === '') {
                $login = 'group';
            }
            $suffix = 0;
            $candidate = $login;
            while (User::query()->whereRaw('LOWER(login) = LOWER(?)', [$candidate])->exists()) {
                $suffix++;
                $candidate = $login.'_'.$suffix;
            }
            $group = User::query()->create([
                'login' => $candidate,
                'hashed_password' => '',
                'firstname' => '',
                'lastname' => $name,
                'admin' => false,
                'status' => User::STATUS_ACTIVE,
                'type' => User::TYPE_GROUP,
                'language' => '',
                'mail_notification' => '',
                'must_change_passwd' => false,
            ]);

            return ApiResult::created(
                ['group' => $this->groupDocument($actor, $group, [])],
                ApiLocation::to($request, 'groups/'.$group->id),
            );
        });
    }

    public function updateGroup(User $actor, int $id, Request $request): ApiResult
    {
        return $this->calls->run(function () use ($actor, $id, $request): ApiResult {
            $group = $this->readableGroup($actor, $id);
            if ($group instanceof ApiResult) {
                return $group;
            }
            if (! $this->admin($actor)) {
                return ApiResult::fail(403, 'You are not authorized to access this page.');
            }
            $attributes = ApiQuery::resource($request, 'group');
            if (array_key_exists('name', $attributes)) {
                $name = trim($this->values->text($attributes['name']));
                if ($name === '') {
                    throw new DomainException('Group name is required.');
                }
                $group->lastname = $name;
                $group->save();
            }

            return ApiResult::noContent();
        });
    }

    public function destroyGroup(User $actor, int $id): ApiResult
    {
        return $this->calls->run(function () use ($actor, $id): ApiResult {
            $group = $this->readableGroup($actor, $id);
            if ($group instanceof ApiResult) {
                return $group;
            }
            $this->accounts->delete($actor, $group);

            return ApiResult::noContent();
        });
    }

    public function addGroupUser(User $actor, int $id, Request $request): ApiResult
    {
        return $this->calls->run(function () use ($actor, $id, $request): ApiResult {
            $group = $this->readableGroup($actor, $id);
            if ($group instanceof ApiResult) {
                return $group;
            }
            $attributes = ApiQuery::resource($request, 'user');
            $raw = $attributes['user_id'] ?? $attributes['id'] ?? null;
            if (! is_numeric($raw)) {
                $raw = $request->input('user_id');
            }
            if (! is_numeric($raw)) {
                return ApiResult::fail(422, 'User is required.');
            }
            $user = User::query()->where('type', User::TYPE_USER)->find((int) $raw);
            if (! $user instanceof User) {
                return ApiResult::fail(404, 'Not found');
            }
            $this->accounts->addToGroup($actor, $user, $group);

            return ApiResult::noContent();
        });
    }

    public function removeGroupUser(User $actor, int $id, int $userId): ApiResult
    {
        return $this->calls->run(function () use ($actor, $id, $userId): ApiResult {
            $group = $this->readableGroup($actor, $id);
            if ($group instanceof ApiResult) {
                return $group;
            }
            $user = User::query()->where('type', User::TYPE_USER)->find($userId);
            if (! $user instanceof User) {
                return ApiResult::fail(404, 'Not found');
            }
            $this->accounts->removeFromGroup($actor, $user, $group);

            return ApiResult::noContent();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(User $actor, User $user): array
    {
        return [
            'id' => (int) $user->id,
            'login' => (string) $user->login,
            'admin' => $user->admin === true,
            'firstname' => (string) $user->firstname,
            'lastname' => (string) $user->lastname,
            'mail' => $this->mail($actor, $user),
            'created_on' => $this->values->stamp($user->created_on),
            'last_login_on' => $this->values->stamp($user->last_login_on),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(User $actor, User $user): array
    {
        $row = $this->summary($actor, $user);
        $row['status'] = (int) $user->status;
        if ($this->admin($actor) || (int) $actor->id === (int) $user->id) {
            $token = Token::query()->where('user_id', $user->id)->where('action', Token::ACTION_API)->value('value');
            $row['api_key'] = is_string($token) ? $token : null;
        }
        $row['custom_fields'] = $this->values->customFields($actor, $user);

        return $row;
    }

    /**
     * @param  array<string, true>  $includes
     * @return array<string, mixed>
     */
    private function groupDocument(User $actor, User $group, array $includes): array
    {
        $row = [
            'id' => (int) $group->id,
            'name' => (string) $group->lastname,
        ];
        if (isset($includes['users'])) {
            $users = [];
            foreach ($group->groupUsers()->orderBy('id')->get() as $user) {
                if ($this->visibility->canSee($actor, $user)) {
                    $users[] = $this->values->ref((int) $user->id, $this->values->personName($user));
                }
            }
            $row['users'] = $users;
        }
        if (isset($includes['memberships'])) {
            $row['memberships'] = [];
        }

        return $row;
    }

    private function findUser(User $actor, string $key): User|ApiResult
    {
        if ($key === 'current') {
            return $actor;
        }
        if (preg_match('/^\d+$/', $key) !== 1) {
            return ApiResult::fail(404, 'Not found');
        }
        $user = User::query()->where('type', User::TYPE_USER)->find((int) $key);
        if (! $user instanceof User) {
            return ApiResult::fail(404, 'Not found');
        }
        if (! $this->admin($actor) && (int) $actor->id !== (int) $user->id && ! $this->visibility->canSee($actor, $user)) {
            return ApiResult::fail(403, 'You are not authorized to access this page.');
        }

        return $user;
    }

    private function readableGroup(User $actor, int $id): User|ApiResult
    {
        $group = User::query()->where('type', User::TYPE_GROUP)->find($id);
        if (! $group instanceof User) {
            return ApiResult::fail(404, 'Not found');
        }
        if (! $this->admin($actor)) {
            return ApiResult::fail(403, 'You are not authorized to access this page.');
        }

        return $group;
    }

    private function admin(User $actor): bool
    {
        return $actor->admin === true
            && $actor->isActive()
            && $this->permissions->isLoggedIn($actor)
            && $this->oauthScope->permits('admin');
    }

    private function mail(User $actor, User $user): ?string
    {
        $address = EmailAddress::query()->where('user_id', $user->id)->where('is_default', true)->value('address');
        if (! is_string($address) || $address === '') {
            return null;
        }
        if ($this->admin($actor) || (int) $actor->id === (int) $user->id) {
            return $address;
        }
        $preference = UserPreference::query()->where('user_id', $user->id)->first();
        if ($preference instanceof UserPreference && $preference->hide_mail === true) {
            return null;
        }

        return $address;
    }

    private function requiredName(mixed $value, string $label): string
    {
        $name = trim($this->values->text($value));
        if ($name === '') {
            throw new DomainException($label.' is required.');
        }

        return $name;
    }
}
