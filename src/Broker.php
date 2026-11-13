<?php

namespace TornadoEngine\Broker;

use App\Models\Setting;
use TornadoEngine\Broker\Models\LogEntry;
use TornadoEngine\Broker\Models\Person;
use TornadoEngine\Broker\Models\Rule;

/**
 * Between Claude and the district's data: pseudonyms on the way out, real addresses on the way in.
 */
class Broker
{
    private const PSEUDONYM = '/\b([stc]-[0-9a-f]{10})@(student|staff|contact)\b/';

    private const PLACEHOLDER = '/\[(?:name|given|family|id)(?: [stc]-[0-9a-f]{10})?\]|\[(?:phone|number)\]/';

    public function __construct(private Filter $filter) {}

    /**
     * Why the broker can't run yet, or null if it can. Without student rules and a directory,
     * real names could reach Claude, so it refuses.
     */
    public function notReady(): ?string
    {
        if (! Rule::query()->exists()) {
            return __('The broker has no student rules yet. An admin adds them on the Administration page.');
        }

        if (Setting::get('broker.refreshed_at') === null || ! Person::whereIn('kind', ['student', 'staff'])->exists()) {
            return __('The broker hasn\'t loaded the directory yet. An admin clicks Refresh now on the Administration page.');
        }

        return null;
    }

    public function ready(): bool
    {
        return $this->notReady() === null;
    }

    public function filter(string $text): string
    {
        return $this->filter->apply($text);
    }

    /**
     * Swaps pseudonyms in Claude's arguments for the real addresses.
     *
     * @param  list<string>  $args
     * @return list<string>
     */
    public function reveal(array $args): array
    {
        return array_map(function (string $arg) {
            if (preg_match(self::PLACEHOLDER, $arg)) {
                throw new BrokerException(__('Placeholders like [name s-…] can\'t be used in commands. Use the person\'s pseudonym address, for example s-0123456789@student.'));
            }

            return preg_replace_callback(self::PSEUDONYM, function ($m) {
                $person = Person::where('pseudonym', $m[1])->where('kind', $m[2])->first();
                if (! $person) {
                    throw new BrokerException(__(':address isn\'t anyone the broker knows.', ['address' => $m[0]]));
                }

                return $person->email;
            }, $arg);
        }, $args);
    }

    /** @param  list<string>  $command  The real command, as run. */
    public function log(string $tool, array $command, ?int $exit, bool $ok): void
    {
        LogEntry::create(['tool' => $tool, 'command' => $command, 'exit' => $exit, 'ok' => $ok]);
    }
}
