<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Redmine 7.0.1 `issue_relations` row.
 */
class IssueRelation extends Model
{
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * @return BelongsTo<Issue, $this>
     */
    public function issueFrom(): BelongsTo
    {
        return $this->belongsTo(Issue::class, 'issue_from_id');
    }

    /**
     * @return BelongsTo<Issue, $this>
     */
    public function issueTo(): BelongsTo
    {
        return $this->belongsTo(Issue::class, 'issue_to_id');
    }
}
