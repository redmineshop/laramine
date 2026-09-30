<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Redmine 7.0.1 `settings` row (`name` / `value`).
 */
class Setting extends Model
{
    public const CREATED_AT = null;

    public const UPDATED_AT = 'updated_on';

    /**
     * @var list<string>
     */
    protected $guarded = ['id'];
}
