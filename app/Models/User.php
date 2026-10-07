<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Redmine 7.0.1 `users` row.
 *
 * `type` stores the STI name (User, Group, AnonymousUser). Subclasses are not mapped yet.
 * Mail lives on `email_addresses`. `hashed_password` is a Redmine SHA-1 digest, not bcrypt.
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const TYPE_USER = 'User';

    public const TYPE_GROUP = 'Group';

    public const TYPE_ANONYMOUS = 'AnonymousUser';

    public const STATUS_ANONYMOUS = 0;

    public const STATUS_ACTIVE = 1;

    public const STATUS_REGISTERED = 2;

    public const STATUS_LOCKED = 3;

    public const CREATED_AT = 'created_on';

    public const UPDATED_AT = 'updated_on';

    /**
     * Every column except the primary key is mass-assignable.
     * Domain assignment rules are not applied yet.
     *
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'hashed_password',
        'salt',
        'twofa_totp_key',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'admin' => 'boolean',
            'must_change_passwd' => 'boolean',
            'twofa_required' => 'boolean',
            'twofa_totp_last_used_at' => 'integer',
            'created_on' => 'datetime',
            'updated_on' => 'datetime',
            'last_login_on' => 'datetime',
            'passwd_changed_on' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return (int) $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Whether this row may keep a web session. Password, LDAP, and TOTP are checked at sign-in.
     */
    public function canKeepWebSession(): bool
    {
        return $this->type === self::TYPE_USER && $this->isActive();
    }

    public function getAuthPasswordName(): string
    {
        return 'hashed_password';
    }

    public function getRememberTokenName(): string
    {
        return '';
    }

    /**
     * @return BelongsTo<AuthSource, $this>
     */
    public function authSource(): BelongsTo
    {
        return $this->belongsTo(AuthSource::class, 'auth_source_id');
    }

    /**
     * @return HasMany<EmailAddress, $this>
     */
    public function emailAddresses(): HasMany
    {
        return $this->hasMany(EmailAddress::class);
    }

    /**
     * @return HasMany<Token, $this>
     */
    public function tokens(): HasMany
    {
        return $this->hasMany(Token::class);
    }

    /**
     * @return HasOne<UserPreference, $this>
     */
    public function preference(): HasOne
    {
        return $this->hasOne(UserPreference::class);
    }

    /**
     * @return HasMany<Member, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Member::class);
    }

    /**
     * Groups this user belongs to (`groups_users.user_id`).
     *
     * @return BelongsToMany<User, $this>
     */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'groups_users', 'user_id', 'group_id');
    }

    /**
     * Users that belong to this group row (`groups_users.group_id`).
     *
     * @return BelongsToMany<User, $this>
     */
    public function groupUsers(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'groups_users', 'group_id', 'user_id');
    }

    /**
     * @return HasMany<Issue, $this>
     */
    public function authoredIssues(): HasMany
    {
        return $this->hasMany(Issue::class, 'author_id');
    }

    /**
     * @return HasMany<Issue, $this>
     */
    public function assignedIssues(): HasMany
    {
        return $this->hasMany(Issue::class, 'assigned_to_id');
    }

    /**
     * `User` or `Group`, matching the STI row. Other user types are not customized here.
     */
    public function customValueType(): string
    {
        return $this->type === self::TYPE_GROUP ? 'Group' : 'User';
    }

    /**
     * Values stored with customized_type User or Group.
     *
     * @return HasMany<CustomValue, $this>
     */
    public function customValues(): HasMany
    {
        return $this->hasMany(CustomValue::class, 'customized_id')
            ->where('custom_values.customized_type', $this->customValueType());
    }
}
