<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Redmine 7.0.1 `import_items` row.
 */
class ImportItem extends Model
{
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * @return BelongsTo<Import, $this>
     */
    public function import(): BelongsTo
    {
        return $this->belongsTo(Import::class);
    }
}
