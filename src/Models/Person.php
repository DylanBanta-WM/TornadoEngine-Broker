<?php

namespace TornadoEngine\Broker\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Someone in the broker's copy of the directory: kind "student", "staff" or "contact", their
 * pseudonym (for example s-0123456789), and the values that identify them, as [value, label] pairs.
 */
class Person extends Model
{
    protected $table = 'broker_people';

    protected $fillable = ['kind', 'email', 'pseudonym', 'values'];

    protected function casts(): array
    {
        return ['values' => 'array'];
    }

    public function address(): string
    {
        return $this->pseudonym.'@'.$this->kind;
    }
}
