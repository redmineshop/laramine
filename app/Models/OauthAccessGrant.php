<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Redmine 7.0.1 `oauth_access_grants` row, including PKCE columns.
 */
class OauthAccessGrant extends Model
{
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'token',
        'code_challenge',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<OauthApplication, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(OauthApplication::class, 'application_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resourceOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resource_owner_id');
    }
}
