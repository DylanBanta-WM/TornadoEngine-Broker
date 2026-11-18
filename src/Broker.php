<?php

namespace TornadoEngine\Broker;

use App\Models\Setting;
use Closure;
use TornadoEngine\Access\Models\Role;
use TornadoEngine\Broker\Models\LogEntry;
use TornadoEngine\Broker\Models\Person;

/**
 * Between Claude and the district's data: pseudonyms on the way out, real addresses on the way in.
 */
class Broker
{
    private const PSEUDONYM = '/\b(p-[0-9a-f]{10})@([a-z0-9-]+)\b/';

    private const PLACEHOLDER = '/\[(?:name|given|family|id|email|phone)(?: (?:p-[0-9a-f]{10}(?:-[0-9a-f]{4})?|v-[0-9a-f]{10}))?\]/';

    /** @var array<string, Closure(list<string>): array<string, list<array{0: string, 1: string}>>> */
    private array $sources = [];

    public function __construct(private Filter $filter) {}

    /**
     * Adds a place the broker learns what identifies people, for example GAM (Google):
     *
     *     $broker->source('google', fn (array $emails) => ['ann@school.org' => [['Ann Lee', 'name'], ['12345', 'externalIds']]]);
     *
     * It gets the emails of everyone given a pseudonym and returns the values to replace, with a
     * label each (name, given and family are matched as words; anything else exactly).
     */
    public function source(string $name, Closure $values): static
    {
        $this->sources[$name] = $values;

        return $this;
    }

    /** @return array<string, Closure> */
    public function sources(): array
    {
        return $this->sources;
    }

    /**
     * The roles whose people are given pseudonyms (all of them until an admin chooses).
     *
     * @return list<int>
     */
    public static function pseudonymRoleIds(): array
    {
        $saved = json_decode(Setting::get('broker.pseudonym_roles') ?? 'null', true);
        $all = Role::pluck('id')->all();

        return is_array($saved) ? array_values(array_intersect($all, $saved)) : $all;
    }

    /**
     * Why the broker can't run yet, or null if it can: it needs to have loaded who gets pseudonyms.
     */
    public function notReady(): ?string
    {
        if (Setting::get('broker.refreshed_at') === null) {
            return __('The broker hasn\'t loaded who gets pseudonyms yet. An admin clicks Refresh now on the Broker tab in Administration.');
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
     * Many short texts at once (for example the words in an image), with one check for changed people.
     *
     * @param  array<array-key, string>  $texts
     * @return array<array-key, string>
     */
    public function filterEach(array $texts): array
    {
        $this->filter->sync();

        return array_map(fn ($text) => $this->filter->apply((string) $text, sync: false), $texts);
    }

    /**
     * Whether a word looks like a name the broker knows, allowing for misreads (OCR): 0 for O, rn for m,
     * a letter or two wrong. For catching what filter() misses in text read from images.
     */
    public function resemblesName(string $word): bool
    {
        return $this->filter->resemblesName($word);
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
                throw new BrokerException(__('Placeholders like [name p-…] can\'t be used in commands. Use the person\'s pseudonym address, for example p-0123456789@student.'));
            }

            return preg_replace_callback(self::PSEUDONYM, function ($m) {
                $person = Person::where('pseudonym', $m[1])->first();
                if (! $person) {
                    throw new BrokerException(__(':address isn\'t anyone the broker knows.', ['address' => $m[0]]));
                }

                return $person->email;
            }, $arg);
        }, $args);
    }

    /**
     * Claude's text for people on the website (never for Claude): pseudonyms become real
     * addresses, and [name …], [given …] and [family …] become the person's names.
     */
    public function display(string $text): string
    {
        $people = [];
        $person = function (string $pseudonym) use (&$people) {
            return $people[$pseudonym] ??= Person::where('pseudonym', $pseudonym)->first();
        };

        // One person's name parts: [name p-…], [given p-…], [family p-…] (with a code when they have more than one).
        $text = preg_replace_callback('/\[(name|given|family) (p-[0-9a-f]{10})(?:-([0-9a-f]{4}))?\]/', function ($m) use ($person) {
            foreach ($person($m[2])?->values ?? [] as [$value, $label]) {
                if ($label === $m[1] && (($m[3] ?? '') === '' || Value::short(mb_strtolower((string) $value)) === $m[3])) {
                    return $value;
                }
            }

            return $m[0];
        }, $text) ?? $text;

        // One person's other values: [id p-…-ab12], and their other addresses: [email p-…-ab12].
        $text = preg_replace_callback('/\[(id|email) (p-[0-9a-f]{10})-([0-9a-f]{4})\]/', function ($m) use ($person) {
            foreach ($person($m[2])?->values ?? [] as [$value, $label]) {
                $compare = $label === 'email' ? strtolower((string) $value) : (string) $value;
                if (($label === 'email') === ($m[1] === 'email') && Value::short($compare) === $m[3]) {
                    return $value;
                }
            }

            return $m[0];
        }, $text) ?? $text;

        // Values that aren't one person's: [id v-…], [name v-…], [phone v-…].
        $text = preg_replace_callback('/\[(?:name|given|family|id|email|phone) (v-[0-9a-f]{10})\]/', fn ($m) => Value::find($m[1]) ?? $m[0], $text) ?? $text;

        return preg_replace_callback(self::PSEUDONYM, fn ($m) => $person($m[1])?->email ?? $m[0], $text) ?? $text;
    }

    /** The placeholder for a value taken out of text that isn't in the directory (a misread name, say), kept so people can see it again. */
    public function placeholder(string $value, string $label = 'name'): string
    {
        return Value::placeholder($value, $label);
    }

    /** @param  list<string>  $command  The real command, as run. */
    public function log(string $tool, array $command, ?int $exit, bool $ok): void
    {
        LogEntry::create(['tool' => $tool, 'command' => $command, 'exit' => $exit, 'ok' => $ok]);
    }
}
