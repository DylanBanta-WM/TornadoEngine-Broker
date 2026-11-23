<?php

namespace TornadoEngine\Broker;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;
use TornadoEngine\Access\Models\Role;
use TornadoEngine\Broker\Models\Person;

/**
 * Who is given a pseudonym: everyone. Every account on the website, and everyone the directories
 * modules add as sources know (GAM for Google, Active Directory, Entra ID), with every value that
 * identifies them. Their pseudonym says their role on the website (p-…@student), or p-…@person.
 */
class Directory
{
    /** Shortest value worth replacing; shorter ones (like "Al") would replace parts of ordinary words. */
    private const MIN_LENGTH = 3;

    /** The pseudonym's ending for someone with no role on the website (only in a directory, say). */
    private const NO_ROLE = 'person';

    public function __construct(private Broker $broker) {}

    /**
     * Everyone, from scratch (nightly, and when the roles are saved).
     *
     * @return string A summary for the scheduled job's result.
     */
    public function refresh(): string
    {
        [$roleOf, $users] = $this->current();
        [$people, $report, $failed] = $this->build($users, $roleOf, everyone: true);

        DB::transaction(function () use ($people) {
            Person::whereNotIn('email', array_keys($people))->delete();
            $this->save($people);
        });

        Setting::put('broker.report', json_encode($report));
        Setting::put('broker.refreshed_at', now()->toIso8601String());
        Setting::put('broker.signature', $this->signature());
        app(Filter::class)->forget();

        return trans_choice(':count person given a pseudonym.|:count people given pseudonyms.', count($people), ['count' => number_format(count($people))])
            .($failed ? ' '.__('Couldn\'t read: :sources.', ['sources' => implode(', ', $failed)]) : '');
    }

    /**
     * Before anything is filtered: if people or their roles changed since the broker last looked
     * (someone added in Access, a role given, a name changed), update just those people, so nobody
     * added during the day reaches Claude unfiltered. Nobody is removed here (keeping a pseudonym a
     * little longer is the safe side); the nightly refresh does that.
     */
    public function syncIfChanged(): void
    {
        if (Setting::get('broker.refreshed_at') === null || ($signature = $this->signature()) === Setting::get('broker.signature')) {
            return;
        }

        [$roleOf, $users] = $this->current();
        $known = Person::get(['email', 'kind', 'values'])->keyBy('email');
        $changed = $users->filter(function (User $user) use ($known, $roleOf) {
            $person = $known->get(strtolower($user->email));
            $values = is_string($person?->values) ? $person->values : json_encode($person?->values);

            return ! $person || $person->kind !== Pseudonym::slug($roleOf[$user->id] ?? self::NO_ROLE)
                || (mb_strlen((string) $user->name) >= self::MIN_LENGTH && ! str_contains((string) $values, json_encode((string) $user->name)));
        });
        if ($changed->isNotEmpty()) {
            [$people] = $this->build($changed, $roleOf);
            $this->save($people);
            app(Filter::class)->forget();
        }
        Setting::put('broker.signature', $signature);
    }

    /** Changes when anyone is added or renamed, or their roles change. */
    private function signature(): string
    {
        $pivot = DB::table('access_role_user')->selectRaw('count(*) as total, coalesce(sum(user_id * 1000003 + role_id), 0) as mix')->first();
        $users = DB::table('users')->selectRaw('count(*) as total, max(updated_at) as latest, coalesce(sum(length(name) * 7 + length(email)), 0) as lengths')->first();
        $roles = DB::table('access_roles')->selectRaw('count(*) as total, coalesce(sum(length(name)), 0) as lengths')->first();

        return implode('|', [$pivot->total, $pivot->mix, $users->total, $users->latest, $users->lengths, $roles->total, $roles->lengths]);
    }

    /** @return array{0: array<int, string>, 1: Collection<int, User>} everyone with an account, and the role that names their pseudonym */
    private function current(): array
    {
        $roles = Role::pluck('name', 'id');
        $roleOf = [];
        foreach (DB::table('access_role_user')->get() as $row) {
            $name = $roles[$row->role_id] ?? null;
            // The first of their roles alphabetically names the pseudonym.
            if ($name !== null && (! isset($roleOf[$row->user_id]) || strcasecmp($name, $roleOf[$row->user_id]) < 0)) {
                $roleOf[$row->user_id] = $name;
            }
        }

        return [$roleOf, User::get(['id', 'name', 'email', 'updated_at'])];
    }

    /**
     * @param  bool  $everyone  also everyone the sources know who has no account (the full refresh)
     * @return array{0: array<string, array>, 1: array<string, int>, 2: list<string>} people's rows, the report, failed sources
     */
    private function build($users, array $roleOf, bool $everyone = false): array
    {
        $emails = $users->pluck('email')->map(fn ($email) => strtolower($email))->values()->all();

        // What identifies people, from each source: everyone it knows (null), or these people. A source that
        // fails is skipped (and reported); one made before sources were asked for everyone is asked for these people.
        $extra = [];
        $failed = [];
        foreach ($this->broker->sources() as $source => $values) {
            try {
                try {
                    $found = $values($everyone ? null : $emails);
                } catch (\TypeError) {
                    $found = $values($emails);
                }
                foreach ($found as $email => $list) {
                    $extra[strtolower($email)] = array_merge($extra[strtolower($email)] ?? [], $list);
                }
            } catch (Throwable $e) {
                report($e);
                $failed[] = $source;
            }
        }

        // Accounts on the website, then people only the directories know.
        $people = $users->mapWithKeys(fn (User $user) => [strtolower($user->email) => ['name' => (string) $user->name, 'kind' => $roleOf[$user->id] ?? self::NO_ROLE]])->all();
        if ($everyone) {
            foreach (array_keys($extra) as $email) {
                $people[$email] ??= ['name' => '', 'kind' => self::NO_ROLE];
            }
        }

        $report = [];
        $rows = [];
        foreach ($people as $email => $person) {
            $values = [[$email, 'email']];
            if ($person['name'] !== '') {
                $values[] = [$person['name'], 'name'];
                $parts = preg_split('/\s+/', trim($person['name']));
                if (count($parts) > 1) {
                    $values[] = [$parts[0], 'given'];
                    $values[] = [end($parts), 'family'];
                }
            }
            foreach ($extra[$email] ?? [] as [$value, $label]) {
                $values[] = [trim((string) $value), $label];
                if (! in_array($label, ['email', 'name', 'given', 'family'], true)) {
                    $report[$label] = ($report[$label] ?? 0) + 1;
                }
            }
            $values = array_values(array_unique(array_filter($values, fn ($v) => mb_strlen($v[0]) >= self::MIN_LENGTH), SORT_REGULAR));

            $rows[$email] = ['kind' => Pseudonym::slug($person['kind']), 'email' => $email, 'pseudonym' => Pseudonym::for($email), 'values' => json_encode($values)];
        }

        return [$rows, $report, $failed];
    }

    private function save(array $people): void
    {
        foreach (array_chunk(array_values($people), 500) as $chunk) {
            Person::upsert(
                array_map(fn ($p) => $p + ['created_at' => now(), 'updated_at' => now()], $chunk),
                ['email'],
                ['kind', 'pseudonym', 'values', 'updated_at'],
            );
        }
    }
}
