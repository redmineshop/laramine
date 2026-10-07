<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Redmine 7.0.1 `documents` row.
 *
 * `category_id` points at a `DocumentCategory` enumeration. Custom values use
 * `customized_type` Document. A custom-field route may still address an id
 * that has no row.
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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
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
     * @return BelongsTo<Enumeration, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Enumeration::class, 'category_id');
    }
}
