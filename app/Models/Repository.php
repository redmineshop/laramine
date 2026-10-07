<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Redmine 7.0.1 `repositories` row.
 *
 * `type` stores the repository class name. This slice does not fetch or browse
 * the repository. The issue history tab reads linked changesets only.
 */
class Repository extends Model
{
    public const CREATED_AT = 'created_on';

    public const UPDATED_AT = null;

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
            'is_default' => 'boolean',
            'created_on' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<Changeset, $this>
     */
    public function changesets(): HasMany
    {
        return $this->hasMany(Changeset::class);
    }
}
