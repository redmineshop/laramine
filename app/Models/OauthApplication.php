<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Redmine 7.0.1 `oauth_applications` row.
 */
class OauthApplication extends Model
{
    /**
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'secret',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'confidential' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<OauthAccessGrant, $this>
     */
    public function accessGrants(): HasMany
    {
        return $this->hasMany(OauthAccessGrant::class, 'application_id');
    }

    /**
     * @return HasMany<OauthAccessToken, $this>
     */
    public function accessTokens(): HasMany
    {
        return $this->hasMany(OauthAccessToken::class, 'application_id');
    }
}
