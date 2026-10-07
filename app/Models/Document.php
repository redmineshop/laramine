<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Document host for `DocumentCustomField` values.
 *
 * The `documents` table is outside the migrated layout, so this model is not
 * queried. Callers set the id and the project relation, and `custom_values`
 * store `customized_type` Document for that id.
 */
class Document extends Model
{
    public $timestamps = false;

    protected $table = 'documents';

    /**
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
