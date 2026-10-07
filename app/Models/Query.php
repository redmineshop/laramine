<?php

namespace App\Models;

use App\Domain\Queries\IssueQueryColumns;
use App\Domain\Queries\QueryPayload;
use App\Domain\Queries\QueryPayloadCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Redmine 7.0.1 `queries` row.
 *
 * `type` is the STI name. IssueQuery and TimeEntryQuery are executed. The other names are stubs.
 * `filters`, `column_names`, `sort_criteria`, and `options` are JSON text.
 * A legacy YAML dump is accepted on read and rewritten as JSON on the next save.
 */
class Query extends Model
{
    protected $table = 'queries';

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
            'filters' => QueryPayloadCast::class.':filters',
            'column_names' => QueryPayloadCast::class.':column_names',
            'sort_criteria' => QueryPayloadCast::class.':sort_criteria',
            'options' => QueryPayloadCast::class.':options',
            'visibility' => 'integer',
        ];
    }

    /**
     * @return list<string>
     */
    public function displayColumns(): array
    {
        $names = QueryPayload::columnNames($this->column_names);

        return $names ?? IssueQueryColumns::DEFAULT;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'queries_roles', 'query_id', 'role_id');
    }
}
