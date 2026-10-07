<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Redmine 7.0.1 `wiki_redirects` row.
 *
 * `wiki_id` is the wiki that owned the old title. `redirects_to_wiki_id` is
 * the wiki that owns the current title. A rename in this tree stays in one wiki.
 */
class WikiRedirect extends Model
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
            'created_on' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Wiki, $this>
     */
    public function wiki(): BelongsTo
    {
        return $this->belongsTo(Wiki::class);
    }
}
