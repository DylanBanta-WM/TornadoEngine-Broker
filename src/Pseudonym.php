<?php

namespace TornadoEngine\Broker;

/**
 * Pseudonyms: s-<10 hex> for students, t- for staff, c- for contacts. The same email address
 * always gets the same pseudonym, so Claude can refer to someone across conversations.
 */
class Pseudonym
{
    public const PREFIX = ['student' => 's', 'staff' => 't', 'contact' => 'c'];

    public static function for(string $kind, string $email): string
    {
        $key = hash('sha256', config('app.key').'|broker-pseudonyms');

        return self::PREFIX[$kind].'-'.substr(hash_hmac('sha256', strtolower($email), $key), 0, 10);
    }
}
