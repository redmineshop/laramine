<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Redmine 7.0.1 `wiki_pages` row.
 *
 * `parent_id` is the page tree. `protected` is the column name from the dump.
 */
class WikiPage extends Model
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
            'protected' => 'boolean',
            'created_on' => 'datetime',
        ];
    }

    public function isProtected(): bool
    {
        return (bool) $this->getAttribute('protected');
    }

    public function markProtected(bool $protected): void
    {
        $this->setAttribute('protected', $protected);
    }

    /**
     * @return BelongsTo<Wiki, $this>
     */
    public function wiki(): BelongsTo
    {
        return $this->belongsTo(Wiki::class);
    }

    /**
     * @return BelongsTo<WikiPage, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<WikiPage, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return HasOne<WikiContent, $this>
     */
    public function content(): HasOne
    {
        return $this->hasOne(WikiContent::class, 'page_id');
    }
}
