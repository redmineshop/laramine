<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Redmine 7.0.1 `journal_details` row.
 */
class JournalDetail extends Model
{
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * @return BelongsTo<Journal, $this>
     */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }
}
