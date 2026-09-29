<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Redmine 7.0.1 `custom_fields_projects` join (no surrogate primary key).
 */
class CustomFieldProject extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'custom_fields_projects';

    protected $primaryKey = 'custom_field_id';

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @return BelongsTo<CustomField, $this>
     */
    public function customField(): BelongsTo
    {
        return $this->belongsTo(CustomField::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
