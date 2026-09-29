<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Redmine 7.0.1 `projects_trackers` join (no surrogate primary key).
 */
class ProjectTracker extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'projects_trackers';

    protected $primaryKey = 'project_id';

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Tracker, $this>
     */
    public function tracker(): BelongsTo
    {
        return $this->belongsTo(Tracker::class);
    }
}
