<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Redmine 7.0.1 `issue_statuses` row.
 */
class IssueStatus extends Model
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
            'is_closed' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Issue, $this>
     */
    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class, 'status_id');
    }

    /**
     * @return HasMany<Tracker, $this>
     */
    public function defaultForTrackers(): HasMany
    {
        return $this->hasMany(Tracker::class, 'default_status_id');
    }
}
