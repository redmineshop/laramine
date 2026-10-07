<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Redmine 7.0.1 `wiki_content_versions` row.
 *
 * `data` stores the zlib payload. `compression` is `gzip` for that payload.
 */
class WikiContentVersion extends Model
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
            'updated_on' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<WikiContent, $this>
     */
    public function content(): BelongsTo
    {
        return $this->belongsTo(WikiContent::class, 'wiki_content_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
