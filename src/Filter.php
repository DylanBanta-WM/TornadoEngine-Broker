<?php

namespace TornadoEngine\Broker;

use TornadoEngine\Broker\Models\Person;

/**
 * Takes the people out of text before Claude sees it: every value in the broker's directory
 * becomes a pseudonym, then a safety net catches what the directory doesn't know about.
 */
class Filter
{
    /** Pseudonyms and placeholders the filter writes; the safety net leaves them alone. */
    private const WRITTEN = '/p-[0-9a-f]{10}@[a-z0-9-]+|\[(?:name|given|family|id) p-[0-9a-f]{10}\]|\[(?:name|given|family|id|phone)\]/';

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
        $this->exact = $this->words = null;
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
        return preg_replace(self::PHONE, '[phone]', $text) ?? $text;
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
    }
}
