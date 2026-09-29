<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Redmine 7.0.1 `workflows` row.
 *
 * `type` distinguishes status transitions from field-permission rules.
 * Neither kind is evaluated here.
 */
class Workflow extends Model
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
            'assignee' => 'boolean',
            'author' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Tracker, $this>
     */
    public function tracker(): BelongsTo
    {
        return $this->belongsTo(Tracker::class);
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * @return BelongsTo<IssueStatus, $this>
     */
    public function oldStatus(): BelongsTo
    {
        return $this->belongsTo(IssueStatus::class, 'old_status_id');
    }

    /**
     * @return BelongsTo<IssueStatus, $this>
     */
    public function newStatus(): BelongsTo
    {
        return $this->belongsTo(IssueStatus::class, 'new_status_id');
    }
}
