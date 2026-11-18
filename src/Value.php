<?php

namespace TornadoEngine\Broker;

use TornadoEngine\Broker\Models\Value as StoredValue;

/**
 * Placeholders for values that aren't one person's: [id v-<10 hex>], [name v-…], [phone v-…]. The code is
 * the same for a value every time; the value is kept (encrypted) so people can see it again.
 */
class Value
{
    public static function code(string $value): string
    {
        return 'v-'.substr(hash_hmac('sha256', $value, hash('sha256', config('app.key').'|broker-values')), 0, 10);
    }

    /** A short code for one of a person's values, after their pseudonym: [id p-0123456789-ab12]. */
    public static function short(string $value): string
    {
        return substr(hash_hmac('sha256', $value, hash('sha256', config('app.key').'|broker-values')), 0, 4);
    }

    public static function token(string $value, string $label): string
    {
        return '['.$label.' '.self::code($value).']';
    }

    /** The placeholder for a value, kept so it can be turned back. */
    public static function placeholder(string $value, string $label): string
    {
        self::remember([[$value, $label]]);

        return self::token($value, $label);
    }

    /** @param  list<array{string, string}>  $values  [value, label] pairs */
    public static function remember(array $values): void
    {
        $codes = [];
        foreach ($values as [$value]) {
            $codes[self::code($value)] = $value;
        }
        if ($codes === []) {
            return;
        }
        $known = [];
        foreach (array_chunk(array_keys($codes), 500) as $chunk) {
            array_push($known, ...StoredValue::whereIn('code', $chunk)->pluck('code')->all());
        }
        foreach (array_diff_key($codes, array_flip($known)) as $code => $value) {
            StoredValue::create(['code' => $code, 'value' => $value]);
        }
    }

    public static function find(string $code): ?string
    {
        return StoredValue::find($code)?->value;
    }
}
