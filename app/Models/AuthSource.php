<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Redmine 7.0.1 `auth_sources` row. `type` is the STI discriminator.
 */
class AuthSource extends Model
{
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'onthefly_register' => 'boolean',
            'tls' => 'boolean',
            'verify_peer' => 'boolean',
        ];
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'auth_source_id');
    }
}
