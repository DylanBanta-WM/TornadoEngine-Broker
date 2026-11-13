<?php

namespace TornadoEngine\Broker;

use App\Models\Setting;
use TornadoEngine\Broker\Models\Person;

/**
 * Takes the people out of text before Claude sees it: every value in the broker's directory
 * becomes a pseudonym, then a safety net catches what the directory doesn't know about.
 */
class Filter
{
    public const DEFAULT_ID_PATTERNS = ['\b\d{5,10}\b'];

    /** Pseudonyms and placeholders the filter writes; the safety net leaves them alone. */
    private const WRITTEN = '/[stc]-[0-9a-f]{10}@(?:student|staff|contact)|\[(?:name|given|family|id) [stc]-[0-9a-f]{10}\]|\[(?:name|given|family|id|phone|number)\]/';

    private const EMAIL = '/[A-Za-z0-9._%+\'-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}/';

    /** Phone numbers: (614) 555-0199, 614-555-0199, 614.555.0199, +1 614 555 0199, and international ones with a +. */
    private const PHONE = '/(?<![\w-])(?:\+\d{1,3}[\s.-]?)?\(?\d{3}\)?[\s.-]?\d{3}[\s.-]?\d{4}(?![\w-])|(?<![\w-])\+\d[\d\s().-]{7,}\d(?![\w-])/';

    /** @var array<string, string>|null exact value => replacement */
    private ?array $exact = null;

    /** @var array<string, string>|null lower-case name (full, given or family) => replacement, matched as whole words in any case */
    private ?array $words = null;

    /** @var array<string, true>|null */
    private ?array $groups = null;

    public function apply(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        $this->load();

        $text = strtr($text, $this->exact);

        foreach (array_chunk($this->words, 300, true) as $chunk) {
            $pattern = '/(?<![\w@.-])(?:'.implode('|', array_map(fn ($w) => preg_quote($w, '/'), array_keys($chunk))).')(?![\w@-])/iu';
            $text = preg_replace_callback($pattern, fn ($m) => $this->words[mb_strtolower($m[0])] ?? $m[0], $text) ?? $text;
        }

        return $this->safetyNet($text);
    }

    /**
     * Forget the loaded directory, after a refresh.
     */
    public function forget(): void
    {
        $this->exact = $this->words = $this->groups = null;
    }

    /** @return list<string> */
    public static function idPatterns(): array
    {
        $saved = Setting::get('broker.id_patterns');

        return $saved === null ? self::DEFAULT_ID_PATTERNS : json_decode($saved, true);
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

    private function scrub(string $text): string
    {
        $text = preg_replace_callback(self::EMAIL, function ($m) {
            $email = strtolower($m[0]);

            return isset($this->groups[$email]) ? $m[0] : $this->contact($email);
        }, $text) ?? $text;

        $text = preg_replace(self::PHONE, '[phone]', $text) ?? $text;

        foreach (self::idPatterns() as $pattern) {
            $text = @preg_replace('/'.str_replace('/', '\/', $pattern).'/', '[number]', $text) ?? $text;
        }

        return $text;
    }

    /**
     * An address the directory doesn't know (often a parent): it becomes a contact.
     */
    private function contact(string $email): string
    {
        $person = Person::firstOrCreate(['email' => $email], [
            'kind' => 'contact',
            'pseudonym' => Pseudonym::for('contact', $email),
            'values' => [[$email, 'email']],
        ]);

        $this->exact[$email] = $person->address();

        return $person->address();
    }

    private function load(): void
    {
        if ($this->exact !== null) {
            return;
        }

        $exact = [];
        $words = [];
        $set = function (array &$map, string $key, string $replacement, string $shared) {
            $map[$key] = isset($map[$key]) && $map[$key] !== $replacement ? $shared : $replacement;
        };

        foreach (Person::query()->select(['kind', 'email', 'pseudonym', 'values'])->cursor() as $person) {
            foreach ($person->values as [$value, $label]) {
                match ($label) {
                    'email' => [$set($exact, $value, $person->address(), '[id]'), $set($exact, strtolower($value), $person->address(), '[id]')],
                    'name', 'given', 'family' => $set($words, mb_strtolower($value), "[{$label} {$person->pseudonym}]", "[{$label}]"),
                    default => $set($exact, $value, "[id {$person->pseudonym}]", '[id]'),
                };
            }
        }

        uksort($words, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        $this->exact = $exact;
        $this->words = $words;
        $this->groups = array_fill_keys(array_map('strtolower', json_decode(Setting::get('broker.groups') ?? '[]', true)), true);
    }
}
