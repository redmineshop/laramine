<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Redmine 7.0.1 `versions` row (target version on a project).
 */
class Version extends Model
{
    public const CREATED_AT = 'created_on';

    public const UPDATED_AT = 'updated_on';

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
            'effective_date' => 'date',
            'created_on' => 'datetime',
            'updated_on' => 'datetime',
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
     * @return HasMany<Issue, $this>
     */
    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class, 'fixed_version_id');
    }

    /**
     * Values stored with customized_type = Version.
     *
     * @return HasMany<CustomValue, $this>
     */
    public function customValues(): HasMany
    {
        return $this->hasMany(CustomValue::class, 'customized_id')
            ->where('custom_values.customized_type', 'Version');
    }
}
