<?php

namespace TornadoEngine\Broker;

use App\Models\Setting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use TornadoEngine\Broker\Models\Person;
use TornadoEngine\Broker\Models\Rule;
use TornadoEngine\Gam\Gam;

/**
 * Refreshes the broker's copy of the directory from GAM: who is a student (by the rules) and who
 * is staff, each one's pseudonym, and every value that identifies them.
 */
class Directory
{
    /** Shortest value worth replacing; shorter ones (like "Al") would replace parts of ordinary words. */
    private const MIN_LENGTH = 3;

    public function __construct(private Gam $gam) {}

    /**
     * @return string A summary for the scheduled job's result.
     */
    public function refresh(): string
    {
        $rules = Rule::all();
        if ($rules->isEmpty()) {
            throw new RuntimeException(__('Add at least one student rule first.'));
        }

        $users = $this->users();
        if (! $users) {
            throw new RuntimeException(__('GAM returned no users.'));
        }

        $groupMembers = [];
        foreach ($rules->where('type', 'group') as $rule) {
            foreach ($this->gam->groupMembers($rule->value) as $email) {
                $groupMembers[$email] = true;
            }
        }

        $report = [];
        $people = [];
        $hasOuRules = $rules->contains('type', 'ou');
        $outsideOus = [];
        foreach ($users as $user) {
            $email = strtolower((string) ($user['primaryEmail'] ?? ''));
            if ($email === '') {
                continue;
            }

            $matched = $this->matches($user, $email, $rules, $groupMembers);
            $student = $matched !== [];

            // Caught by an email pattern or a group, but in none of the student OUs: worth a look.
            if ($student && $hasOuRules && ! in_array('ou', $matched, true)) {
                $outsideOus[] = [
                    'name' => (string) ($user['name']['fullName'] ?? ''),
                    'email' => $email,
                    'ou' => (string) ($user['orgUnitPath'] ?? ''),
                    'suspended' => (bool) ($user['suspended'] ?? false),
                    'via' => $matched,
                ];
            }
            $values = $this->identifyingValues($user, $student, $report);
            $kind = $student ? 'student' : 'staff';

            $people[$email] = ['kind' => $kind, 'email' => $email, 'pseudonym' => Pseudonym::for($kind, $email), 'values' => json_encode($values)];
        }

        DB::transaction(function () use ($people) {
            Person::whereIn('kind', ['student', 'staff'])->whereNotIn('email', array_keys($people))->delete();
            foreach (array_chunk(array_values($people), 500) as $chunk) {
                Person::upsert(
                    array_map(fn ($p) => $p + ['created_at' => now(), 'updated_at' => now()], $chunk),
                    ['email'],
                    ['kind', 'pseudonym', 'values', 'updated_at'],
                );
            }
        });

        Setting::put('broker.groups', json_encode(array_column($this->gam->groups(), 'email')));
        Setting::put('broker.report', json_encode($report));
        Setting::put('broker.outside_ous', json_encode($outsideOus));
        Setting::put('broker.refreshed_at', now()->toIso8601String());

        $students = count(array_filter($people, fn ($p) => $p['kind'] === 'student'));

        return __(':students students and :staff staff', ['students' => number_format($students), 'staff' => number_format(count($people) - $students)]);
    }

    /** @return list<array<string, mixed>> Every user, as GAM's JSON. */
    private function users(): array
    {
        $result = $this->gam->run(['print', 'users', 'allfields', 'formatjson']);
        if ($result['exit'] !== 0) {
            throw new RuntimeException(trim($result['stderr']) ?: __('GAM failed.'));
        }

        $lines = preg_split('/\r?\n/', trim($result['stdout']));
        $header = str_getcsv((string) array_shift($lines), escape: '');
        $json = array_search('JSON', $header, true);

        $users = [];
        foreach (array_filter($lines) as $line) {
            $row = str_getcsv($line, escape: '');
            $user = json_decode($row[$json === false ? count($row) - 1 : $json] ?? '', true);
            if (is_array($user)) {
                $users[] = $user;
            }
        }

        return $users;
    }

    /**
     * Which kinds of rule this person matches: "group", "ou" and/or "email". None means staff.
     *
     * @param  Collection<int, Rule>  $rules
     * @param  array<string, true>  $groupMembers
     * @return list<string>
     */
    private function matches(array $user, string $email, $rules, array $groupMembers): array
    {
        $matched = isset($groupMembers[$email]) ? ['group'] : [];

        $ou = (string) ($user['orgUnitPath'] ?? '');
        $addresses = array_merge([$email], array_map('strtolower', $user['aliases'] ?? []));

        foreach ($rules as $rule) {
            if ($rule->type === 'ou') {
                $path = rtrim($rule->value, '/');
                if (strcasecmp($ou, $path) === 0 || ($rule->sub_ous && stripos($ou, $path.'/') === 0)) {
                    $matched[] = 'ou';
                }
            }

            if ($rule->type === 'email') {
                foreach ($addresses as $address) {
                    if (@preg_match('/'.str_replace('/', '\/', $rule->value).'/i', $address) === 1) {
                        $matched[] = 'email';
                        break;
                    }
                }
            }
        }

        return array_values(array_unique($matched));
    }

    /**
     * Everything that could identify this person. For students that's every field Google can
     * hold an ID, phone, address or relation in, so it's covered wherever a district keeps them.
     *
     * @param  array<string, int>  $report  Counts of students with a value in each field (no values).
     * @return list<array{0: string, 1: string}> [value, label]
     */
    private function identifyingValues(array $user, bool $student, array &$report): array
    {
        $values = [];
        $add = function (mixed $value, string $label) use (&$values) {
            if (is_scalar($value) && ! is_bool($value) && mb_strlen(trim((string) $value)) >= self::MIN_LENGTH) {
                $values[] = [trim((string) $value), $label];
            }
        };

        $add($user['primaryEmail'] ?? null, 'email');
        foreach (array_merge($user['aliases'] ?? [], $user['nonEditableAliases'] ?? []) as $alias) {
            $add($alias, 'email');
        }
        foreach ($user['emails'] ?? [] as $email) {
            $add($email['address'] ?? null, 'email');
        }
        $add($user['name']['fullName'] ?? null, 'name');
        $add($user['name']['givenName'] ?? null, 'given');
        $add($user['name']['familyName'] ?? null, 'family');

        if (! $student) {
            return $values;
        }

        $fields = [
            'externalIds' => fn ($item) => [$item['value'] ?? null],
            'phones' => fn ($item) => [$item['value'] ?? null],
            'relations' => fn ($item) => [$item['value'] ?? null],
            'addresses' => fn ($item) => [$item['formatted'] ?? null, $item['streetAddress'] ?? null, $item['poBox'] ?? null, $item['postalCode'] ?? null],
            'organizations' => fn ($item) => [$item['costCenter'] ?? null, $item['description'] ?? null],
            'ims' => fn ($item) => [$item['im'] ?? null],
            'keywords' => fn ($item) => [$item['value'] ?? null],
            'websites' => fn ($item) => [$item['value'] ?? null],
        ];

        foreach ($fields as $field => $pick) {
            $found = false;
            foreach ($user[$field] ?? [] as $item) {
                foreach ($pick(is_array($item) ? $item : []) as $value) {
                    if ($value !== null && $value !== '') {
                        $add($value, 'id');
                        $found = true;
                    }
                }
            }
            if ($found) {
                $report[$field] = ($report[$field] ?? 0) + 1;
            }
        }

        foreach (['recoveryEmail', 'recoveryPhone'] as $field) {
            if (! empty($user[$field])) {
                $add($user[$field], 'id');
                $report[$field] = ($report[$field] ?? 0) + 1;
            }
        }

        foreach ($user['customSchemas'] ?? [] as $schema => $schemaFields) {
            foreach ((array) $schemaFields as $name => $value) {
                $leaves = [];
                if (is_array($value)) {
                    array_walk_recursive($value, function ($leaf) use (&$leaves) {
                        $leaves[] = $leaf;
                    });
                } else {
                    $leaves = [$value];
                }
                $leaves = array_filter($leaves, fn ($leaf) => $leaf !== null && $leaf !== '' && ! is_bool($leaf));
                foreach ($leaves as $leaf) {
                    $add($leaf, 'id');
                }
                if ($leaves) {
                    $report["customSchemas.{$schema}.{$name}"] = ($report["customSchemas.{$schema}.{$name}"] ?? 0) + 1;
                }
            }
        }

        if (! empty($user['notes']['value'])) {
            $add($user['notes']['value'], 'id');
        }

        return $values;
    }
}
