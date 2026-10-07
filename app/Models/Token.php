<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Redmine 7.0.1 `tokens` row.
 *
 * Named actions: session, api, feeds, recovery, register, autologin, twofa_backup_code.
 * `recovery` and `register` expire after one day. `api` and `feeds` do not.
 * `session`, `autologin`, and `twofa_backup_code` keep at most ten rows.
 */
class Token extends Model
{
    public const ACTION_SESSION = 'session';

    public const ACTION_API = 'api';

    public const ACTION_FEEDS = 'feeds';

    public const ACTION_RECOVERY = 'recovery';

    public const ACTION_REGISTER = 'register';

    public const ACTION_AUTOLOGIN = 'autologin';

    public const ACTION_TWOFA_BACKUP = 'twofa_backup_code';

    public const CREATED_AT = 'created_on';

    public const UPDATED_AT = 'updated_on';

    /**
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'value',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_on' => 'datetime',
            'updated_on' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
