<?php

namespace TornadoEngine\Broker\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A value the broker took out of text that isn't one person's (shared by a few people, or a phone number
 * the directory doesn't know), kept encrypted under its placeholder's code so it can be put back for people.
 */
class Value extends Model
{
    protected $table = 'broker_values';

    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    public const UPDATED_AT = null;

    protected $fillable = ['code', 'value'];

    protected function casts(): array
    {
        return ['value' => 'encrypted'];
    }
}
