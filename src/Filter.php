<?php

namespace TornadoEngine\Broker;

use Illuminate\Support\Str;
use TornadoEngine\Broker\Models\Person;

/**
 * Takes the people out of text before Claude sees it: every value in the broker's directory
 * becomes a pseudonym, then a safety net catches what the directory doesn't know about.
 */
class Filter
{
    /** Pseudonyms and placeholders the filter writes; the safety net leaves them alone. */
    public const WRITTEN = '/p-[0-9a-f]{10}@[a-z0-9-]+|\[(?:name|given|family|id|phone) (?:p-[0-9a-f]{10}(?:-[0-9a-f]{4})?|v-[0-9a-f]{10})\]/';

    /** A value more people share than this doesn't identify anyone (for example "student" as an ID type), so it's left alone. */
    public const SHARED_LIMIT = 25;

    /** Phone numbers: (614) 555-0199, 614-555-0199, 614.555.0199, +1 614 555 0199, and international ones with a +. */
    private const PHONE = '/(?<![\w-])(?:\+\d{1,3}[\s.-]?)?\(?\d{3}\)?[\s.-]?\d{3}[\s.-]?\d{4}(?![\w-])|(?<![\w-])\+\d[\d\s().-]{7,}\d(?![\w-])/';

    /** @var array<string, string>|null exact value => replacement */
    private ?array $exact = null;

    /** @var array<string, string>|null lower-case name (full, given or family) => replacement, matched as whole words in any case */
    private ?array $words = null;

    public function apply(string $text, bool $sync = true): string
    {
        if ($text === '') {
            return $text;
        }

        // Pick up anyone added or changed since the broker last looked; if that fails, nothing passes.
        if ($sync) {
            $this->sync();
        }

        $this->load();

        // Whole values only: an ID inside a longer word or number is a different thing.
        foreach (array_chunk($this->exact, 300, true) as $chunk) {
            $pattern = '/'.implode('|', array_map(fn ($v) => self::whole((string) $v), array_keys($chunk))).'/u';
            $text = preg_replace_callback($pattern, fn ($m) => $this->exact[$m[0]] ?? $m[0], $text) ?? $text;
        }

        foreach (array_chunk($this->words, 300, true) as $chunk) {
            $pattern = '/(?<![\w@.-])(?:'.implode('|', array_map(fn ($w) => preg_quote($w, '/'), array_keys($chunk))).')(?![\w@-])/iu';
            $text = preg_replace_callback($pattern, fn ($m) => $this->words[mb_strtolower($m[0])] ?? $m[0], $text) ?? $text;
        }

        return $this->safetyNet($text);
    }

    /**
     * Forget the loaded directory, after a refresh.
     */
    /** Picks up anyone added or changed since the broker last looked; if that fails, nothing passes. */
    public function sync(): void
    {
        try {
            app(Directory::class)->syncIfChanged();
        } catch (BrokerException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);
            throw new BrokerException(__('The broker couldn\'t update who gets pseudonyms, so nothing was passed to Claude.'));
        }
    }

    public function forget(): void
    {
        $this->exact = $this->words = $this->fuzzy = null;
    }

    /** Characters OCR mixes up, each mapped to one stand-in (and letter pairs read as one letter). */
    private const CONFUSED = ['rn' => 'm', 'vv' => 'w', 'cl' => 'd', '0' => 'o', '1' => 'l', 'i' => 'l', '|' => 'l', '!' => 'l', '5' => 's', '$' => 's', '8' => 'b', '2' => 'z', '6' => 'g', '@' => 'a'];

    /** @var array<int, list<string>>|null name parts by length, with confused characters swapped */
    private ?array $fuzzy = null;

    /**
     * Whether a word (read by OCR, say) looks like a name part the broker knows, allowing for misreads:
     * confused characters (0 and O, 1 and l, rn and m…) and a letter or two wrong.
     */
    public function resemblesName(string $word): bool
    {
        $word = self::confused($word);
        $length = strlen($word);
        if ($length < 4) {
            return false;
        }
        $this->loadFuzzy();
        $allowed = $length >= 8 ? 2 : 1;
        for ($other = $length - $allowed; $other <= $length + $allowed; $other++) {
            foreach ($this->fuzzy[$other] ?? [] as $name) {
                if (levenshtein($word, $name) <= ($length <= 4 ? 0 : $allowed)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function confused(string $text): string
    {
        $text = mb_strtolower(Str::ascii($text));
        $text = preg_replace('/[^a-z0-9|!$@]/', '', $text) ?? '';

        return strtr($text, self::CONFUSED);
    }

    private function loadFuzzy(): void
    {
        if ($this->fuzzy !== null) {
            return;
        }
        $this->load();
        $fuzzy = [];
        foreach (array_keys($this->words) as $name) {
            foreach (preg_split('/[\s\-\']+/u', $name) as $part) {
                $part = self::confused($part);
                if (strlen($part) >= 4) {
                    $fuzzy[strlen($part)][$part] = $part;
                }
            }
        }
        $this->fuzzy = array_map('array_values', $fuzzy);
    }

    private function safetyNet(string $text): string
    {
        // Only the parts between pseudonyms the filter already wrote.
        $parts = preg_split(self::WRITTEN, $text, -1, PREG_SPLIT_OFFSET_CAPTURE);
        $out = '';
        $last = 0;
        foreach ($parts as [$part, $offset]) {
            $out .= substr($text, $last, $offset - $last).$this->scrub($part);
            $last = $offset + strlen($part);
        }

        return $out.substr($text, $last);
    }

    /** A value as a pattern that only matches it whole: digits not inside a longer number, letters not inside a longer word. */
    private static function whole(string $value): string
    {
        $edge = fn (string $char) => match (true) {
            ctype_digit($char) => '0-9',
            ctype_alpha($char) => 'A-Za-z',
            default => null,
        };
        $before = $edge($value[0]);
        $after = $edge(substr($value, -1));

        return ($before ? "(?<![{$before}])" : '').preg_quote($value, '/').($after ? "(?![{$after}])" : '');
    }

    private function scrub(string $text): string
    {
        return preg_replace_callback(self::PHONE, fn ($m) => Value::placeholder($m[0], 'phone'), $text) ?? $text;
    }

    private function load(): void
    {
        if ($this->exact !== null) {
            return;
        }

        // Who has each value, to tell one person's value from one many share.
        $owners = [];
        $people = [];
        foreach (Person::query()->select(['kind', 'email', 'pseudonym', 'values'])->cursor() as $person) {
            $people[$person->pseudonym] = $person;
            foreach ($person->values as [$value, $label]) {
                $key = in_array($label, ['name', 'given', 'family'], true) ? mb_strtolower($value) : ($label === 'email' ? strtolower($value) : $value);
                $owners[$label === 'email' ? 'email' : (in_array($label, ['name', 'given', 'family'], true) ? 'word' : 'id')][$key][$person->pseudonym] = [$value, $label];
            }
        }

        $exact = [];
        $words = [];
        $shared = [];
        foreach ($owners['email'] ?? [] as $key => $who) {
            [$value] = reset($who);
            if (count($who) === 1) {
                $replacement = $people[array_key_first($who)]->address();
            } else {
                $replacement = Value::token($value, 'id');
                $shared[] = [$value, 'id'];
            }
            $exact[$value] = $replacement;
            $exact[$key] = $replacement;
        }
        foreach ($owners['word'] ?? [] as $key => $who) {
            [$value, $label] = reset($who);
            if (count($who) === 1) {
                $words[$key] = "[{$label} ".array_key_first($who).']';
            } else {
                $words[$key] = Value::token($value, $label);
                $shared[] = [$value, $label];
            }
        }
        foreach ($owners['id'] ?? [] as $key => $who) {
            $value = (string) $key;
            if (count($who) > self::SHARED_LIMIT || mb_strlen($value) < 3) {
                continue;
            }
            if (count($who) === 1) {
                $exact[$value] = '[id '.array_key_first($who).'-'.Value::short($value).']';
            } else {
                $exact[$value] = Value::token($value, 'id');
                $shared[] = [$value, 'id'];
            }
        }
        // Shared values are kept (encrypted) so their placeholders can be turned back into them.
        Value::remember($shared);

        uksort($exact, fn ($a, $b) => mb_strlen((string) $b) <=> mb_strlen((string) $a));
        uksort($words, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        $this->exact = $exact;
        $this->words = $words;
    }
}
