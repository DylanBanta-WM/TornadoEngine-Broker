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
 * Who is given a pseudonym: everyone with an account in one of the broker's pseudonym roles.
 * Their names and emails come from Access; other identifying values from sources modules add
 * (GAM, Active Directory, Entra ID).
 */
class Directory
{
    /** Shortest value worth replacing; shorter ones (like "Al") would replace parts of ordinary words. */
    private const MIN_LENGTH = 3;

    public function __construct(private Broker $broker) {}

    /**
     * Everyone, from scratch (nightly, and Refresh now).
     *
     * @return string A summary for the scheduled job's result.
     */
    public function refresh(): string
    {
        [$roleOf, $users] = $this->current();
        [$people, $report, $failed] = $this->build($users, $roleOf);

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

            return ! $person || $person->kind !== Pseudonym::slug($roleOf[$user->id])
                || (mb_strlen((string) $user->name) >= self::MIN_LENGTH && ! str_contains((string) $values, json_encode((string) $user->name)));
        });
        if ($changed->isNotEmpty()) {
            [$people] = $this->build($changed, $roleOf);
            $this->save($people);
            app(Filter::class)->forget();
        }
        Setting::put('broker.signature', $signature);
    }

    /** Changes when anyone is added, renamed, or given or losing a pseudonym role. */
    private function signature(): string
    {
        $pivot = DB::table('access_role_user')->whereIn('role_id', Broker::pseudonymRoleIds())
            ->selectRaw('count(*) as total, coalesce(sum(user_id * 1000003 + role_id), 0) as mix')->first();
        $users = DB::table('users')->selectRaw('count(*) as total, max(updated_at) as latest, coalesce(sum(length(name) * 7 + length(email)), 0) as lengths')->first();

        return implode('|', [$pivot->total, $pivot->mix, $users->total, $users->latest, $users->lengths, implode(',', Broker::pseudonymRoleIds())]);
    }

    /** @return array{0: array<int, string>, 1: Collection<int, User>} who has a pseudonym role, and which names it */
    private function current(): array
    {
        $roles = Role::whereIn('id', Broker::pseudonymRoleIds())->orderBy('name')->get();
        $roleOf = [];
        foreach (DB::table('access_role_user')->whereIn('role_id', $roles->pluck('id'))->get() as $row) {
            $name = $roles->firstWhere('id', $row->role_id)->name;
            // The first pseudonym role alphabetically names the pseudonym.
            if (! isset($roleOf[$row->user_id]) || strcasecmp($name, $roleOf[$row->user_id]) < 0) {
                $roleOf[$row->user_id] = $name;
            }
        }

        return [$roleOf, User::whereIn('id', array_keys($roleOf))->get(['id', 'name', 'email', 'updated_at'])];
    }

    /** @return array{0: array<string, array>, 1: array<string, int>, 2: list<string>} people's rows, the report, failed sources */
    private function build($users, array $roleOf): array
    {
        $emails = $users->pluck('email')->map(fn ($email) => strtolower($email))->values()->all();

        // What else identifies them, from each source; a source that fails is skipped (and reported).
        $extra = [];
        $failed = [];
        foreach ($emails ? $this->broker->sources() : [] as $source => $values) {
            try {
                foreach ($values($emails) as $email => $list) {
                    $extra[strtolower($email)] = array_merge($extra[strtolower($email)] ?? [], $list);
                }
            } catch (Throwable $e) {
                report($e);
                $failed[] = $source;
            }
        }

        $report = [];
        $people = [];
        foreach ($users as $user) {
            $email = strtolower($user->email);
            $values = [[$email, 'email'], [(string) $user->name, 'name']];
            $parts = preg_split('/\s+/', trim((string) $user->name));
            if (count($parts) > 1) {
                $values[] = [$parts[0], 'given'];
                $values[] = [end($parts), 'family'];
            }
            foreach ($extra[$email] ?? [] as [$value, $label]) {
                $values[] = [trim((string) $value), $label];
                if (! in_array($label, ['email', 'name', 'given', 'family'], true)) {
                    $report[$label] = ($report[$label] ?? 0) + 1;
                }
            }
            $values = array_values(array_unique(array_filter($values, fn ($v) => mb_strlen($v[0]) >= self::MIN_LENGTH), SORT_REGULAR));

            $people[$email] = ['kind' => Pseudonym::slug($roleOf[$user->id]), 'email' => $email, 'pseudonym' => Pseudonym::for($email), 'values' => json_encode($values)];
        }

        return [$people, $report, $failed];
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
