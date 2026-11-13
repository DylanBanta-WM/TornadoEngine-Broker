<?php

namespace TornadoEngine\Broker\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A rule for who counts as a student: type "ou" (an OU path), "group" (a Google Group's email)
 * or "email" (a regular expression the person's email address matches). At most one rule is
 * primary: the one that should cover every student, which the others are checked against.
 */
class Rule extends Model
{
    protected $table = 'broker_rules';

    protected $fillable = ['type', 'value', 'sub_ous', 'primary'];

    /**
     * How the rule reads on the page, for example: the email pattern ^wm\d{2}….
     */
    public static function describe(string $type, string $value): string
    {
        return match ($type) {
            'ou' => __('the OU :value', ['value' => $value]),
            'group' => __('the Google Group :value', ['value' => $value]),
            'email' => __('the email pattern :value', ['value' => $value]),
            default => $value,
        };
    }

    protected function casts(): array
    {
        return ['sub_ous' => 'boolean', 'primary' => 'boolean'];
    }
}
