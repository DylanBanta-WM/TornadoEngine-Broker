<?php

namespace TornadoEngine\Broker\Models;

use Illuminate\Database\Eloquent\Model;

class LogEntry extends Model
{
    protected $table = 'broker_log';

    public const UPDATED_AT = null;

    protected $fillable = ['tool', 'command', 'exit', 'ok'];

    protected function casts(): array
    {
        return ['command' => 'array', 'ok' => 'boolean', 'created_at' => 'datetime'];
    }
}
