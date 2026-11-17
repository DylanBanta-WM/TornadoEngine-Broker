<?php

namespace TornadoEngine\Broker;

use Illuminate\Support\Str;

/**
 * Pseudonyms: p-<10 hex>@<role>, for example p-1a2b3c4d5e@student. The code is the same for an
 * email address every time; the part after @ comes from the person's role name.
 */
class Pseudonym
{
    public static function for(string $email): string
    {
        $key = hash('sha256', config('app.key').'|broker-pseudonyms');

        return 'p-'.substr(hash_hmac('sha256', strtolower($email), $key), 0, 10);
    }

    /**
     * A role name as the part after @: "Office Staff #2" becomes office-staff-2.
     */
    public static function slug(string $role): string
    {
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(Str::ascii($role))), '-');

        return $slug !== '' ? $slug : 'person';
    }
}
