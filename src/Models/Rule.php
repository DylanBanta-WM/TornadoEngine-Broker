<?php

namespace TornadoEngine\Broker\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A rule for who counts as a student: type "ou" (an OU path), "group" (a Google Group's email)
 * or "email" (a regular expression the person's email address matches).
 */
class Rule extends Model
{
    protected $table = 'broker_rules';

    protected $fillable = ['type', 'value', 'sub_ous'];

    protected function casts(): array
    {
        return ['sub_ous' => 'boolean'];
    }
}
