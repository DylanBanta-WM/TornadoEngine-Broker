<?php

namespace TornadoEngine\Broker;

use App\Models\Setting;
use App\Models\User;
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
     * @return string A summary for the scheduled job's result.
     */
    public function refresh(): string
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

        $users = User::whereIn('id', array_keys($roleOf))->get(['id', 'name', 'email']);
        $emails = $users->pluck('email')->map(fn ($email) => strtolower($email))->all();

        // What else identifies them, from each source; a source that fails is skipped (and reported).
        $extra = [];
        $failed = [];
        foreach ($this->broker->sources() as $source => $values) {
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

        DB::transaction(function () use ($people) {
            Person::whereNotIn('email', array_keys($people))->delete();
            foreach (array_chunk(array_values($people), 500) as $chunk) {
                Person::upsert(
                    array_map(fn ($p) => $p + ['created_at' => now(), 'updated_at' => now()], $chunk),
                    ['email'],
                    ['kind', 'pseudonym', 'values', 'updated_at'],
                );
            }
        });

        Setting::put('broker.report', json_encode($report));
        Setting::put('broker.refreshed_at', now()->toIso8601String());
        app(Filter::class)->forget();

        return trans_choice(':count person given a pseudonym.|:count people given pseudonyms.', count($people), ['count' => number_format(count($people))])
            .($failed ? ' '.__('Couldn\'t read: :sources.', ['sources' => implode(', ', $failed)]) : '');
    }
}
