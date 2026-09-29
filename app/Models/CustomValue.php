<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Redmine 7.0.1 `custom_values` row (`customized_type` / `customized_id`).
 *
 * `customized_type` stores the Redmine class name (`Issue`, `Project`, `User`,
 * `Group`, `TimeEntry`, `Version`). `customized()` resolves only when that
 * name is in the morph map; domain code reads values by the type string.
 */
class CustomValue extends Model
{
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * @return BelongsTo<CustomField, $this>
     */
    public function customField(): BelongsTo
    {
        return $this->belongsTo(CustomField::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function customized(): MorphTo
    {
        return $this->morphTo();
    }
}
