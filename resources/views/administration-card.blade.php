@php
    $broker = app(\TornadoEngine\Broker\Broker::class);
    $notReady = $broker->notReady();
    $rules = \TornadoEngine\Broker\Models\Rule::orderByDesc('primary')->orderBy('type')->orderBy('value')->get();
    $counts = \TornadoEngine\Broker\Models\Person::query()->selectRaw('kind, count(*) as total')->groupBy('kind')->pluck('total', 'kind');
    $report = json_decode(\App\Models\Setting::get('broker.report') ?? '[]', true);
    $missed = json_decode(\App\Models\Setting::get('broker.missed_by_primary') ?? 'null', true);
    $describe = fn (array $rule) => \TornadoEngine\Broker\Models\Rule::describe($rule['type'], $rule['value']);
    $refreshed = \App\Models\Setting::get('broker.refreshed_at');
    $box = 'rounded-md border px-4 py-3';
    $types = ['ou' => __('OU'), 'group' => __('Google Group'), 'email' => __('Email pattern')];
@endphp

<x-settings-card :title="__('Broker')">
    <p>{{ __('Claude works with your directory through the broker, which replaces every person with a pseudonym. It only runs once it knows who your students are.') }}</p>

    @if ($notReady)
        <p class="{{ $box }} border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-950/40 text-amber-900 dark:text-amber-200">{{ $notReady }}</p>
    @else
        <p class="{{ $box }} border-green-300 dark:border-green-800 bg-green-50 dark:bg-green-900/30 text-green-800 dark:text-green-300">
            {{ __('Ready: :students students and :staff staff, refreshed :time.', ['students' => number_format($counts['student'] ?? 0), 'staff' => number_format($counts['staff'] ?? 0), 'time' => \Illuminate\Support\Carbon::parse($refreshed)->diffForHumans()]) }}
        </p>
    @endif
    @if (session('broker_status'))
        <p class="{{ $box }} border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/40">{{ session('broker_status') }}</p>
    @endif
    @if ($errors->has('broker'))
        <p class="{{ $box }} border-red-300 dark:border-red-800 bg-red-50 dark:bg-red-900/30 text-red-800 dark:text-red-300">{{ $errors->first('broker') }}</p>
    @endif

    <div>
        <h4 class="font-medium text-gray-900 dark:text-gray-100">{{ __('Who is a student') }}</h4>
        <p class="text-gray-600 dark:text-gray-400">{{ __('Anyone matching at least one rule is a student. Everyone else in Google is staff.') }}</p>
        <p class="mt-1 text-gray-600 dark:text-gray-400">{{ __('You can mark one rule as primary: the one that should cover every student, such as your students\' OU. After each refresh, students caught by one of the other rules but not by the primary rule are listed below, so you can see who it\'s missing (for example, a new student not yet moved into the student OU). They\'re still treated as students. Without a primary rule, rules aren\'t compared.') }}</p>
        <ul class="mt-2 divide-y divide-gray-200 dark:divide-gray-700 rounded-md border border-gray-200 dark:border-gray-700">
            @forelse ($rules as $rule)
                <li class="flex flex-col gap-1 px-3 py-2 sm:flex-row sm:items-center sm:justify-between sm:gap-3">
                    <span class="min-w-0 break-all">
                        @if ($rule->primary)
                            <span class="me-1 rounded bg-indigo-50 dark:bg-indigo-900/50 px-1.5 py-0.5 text-xs font-medium text-indigo-700 dark:text-indigo-300">{{ __('Primary') }}</span>
                        @endif
                        <span class="text-gray-500 dark:text-gray-400">{{ $types[$rule->type] }}:</span> <code class="font-mono">{{ $rule->value }}</code>@if ($rule->type === 'ou' && $rule->sub_ous) <span class="text-gray-500 dark:text-gray-400">{{ __('and sub-OUs') }}</span>@endif
                    </span>
                    <span class="-ms-2 flex shrink-0 sm:ms-0">
                        <form method="POST" action="{{ route('broker.rules.primary', $rule) }}">
                            @csrf
                            <button class="min-h-11 px-2 text-sm text-gray-700 dark:text-gray-300 underline">{{ $rule->primary ? __('Not primary') : __('Make primary') }}</button>
                        </form>
                        <form method="POST" action="{{ route('broker.rules.destroy', $rule) }}">
                            @csrf
                            @method('DELETE')
                            <button class="min-h-11 px-2 text-sm text-red-700 dark:text-red-400 underline">{{ __('Remove') }}</button>
                        </form>
                    </span>
                </li>
            @empty
                <li class="px-3 py-3 text-gray-600 dark:text-gray-400">{{ __('No rules yet.') }}</li>
            @endforelse
        </ul>

        <form method="POST" action="{{ route('broker.rules.store') }}" class="mt-3 space-y-3" x-data="{ type: @js(old('type', 'ou')) }">
            @csrf
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div>
                    <x-input-label for="broker-type" :value="__('Rule')" />
                    <select id="broker-type" name="type" x-model="type" class="mt-1 block w-full min-h-11 rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 text-base focus:border-indigo-500 focus:ring-indigo-500">
                        @foreach ($types as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <x-input-label for="broker-value" :value="__('Value')" />
                    <x-text-input id="broker-value" name="value" class="mt-1 block w-full font-mono" type="text" :value="old('value')" required
                                  x-bind:placeholder="{ ou: '/Students', group: 'all-students@example.org', email: '^[a-z]+\\d{2}@example\\.org$' }[type]" />
                </div>
            </div>
            <x-input-error :messages="$errors->get('value')" />
            <div class="flex flex-wrap items-center justify-between gap-3">
                <label x-show="type === 'ou'" class="inline-flex min-h-11 items-center gap-2">
                    <input type="checkbox" name="sub_ous" value="1" checked class="rounded text-indigo-600 border-gray-300 dark:border-gray-700 dark:bg-gray-900 focus:ring-indigo-500">
                    <span>{{ __('Include sub-OUs') }}</span>
                </label>
                <x-primary-button class="ms-auto">{{ __('Add rule') }}</x-primary-button>
            </div>
        </form>
    </div>

    @if ($report)
        <div>
            <h4 class="font-medium text-gray-900 dark:text-gray-100">{{ __('Where student information was found') }}</h4>
            <p class="text-gray-600 dark:text-gray-400">{{ __('Number of students with a value in each field. These values are hidden from Claude.') }}</p>
            <ul class="mt-1 space-y-0.5">
                @foreach ($report as $field => $count)
                    <li><code class="font-mono">{{ $field }}</code>: {{ number_format($count) }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($missed && $rules->firstWhere('primary', true))
        <div>
            <h4 class="font-medium text-gray-900 dark:text-gray-100">{{ __('Students your primary rule missed') }} ({{ count($missed['students']) }})</h4>
            <p class="text-gray-600 dark:text-gray-400">{{ __('Your primary rule is :rule. These students were caught by another rule but not by it, as of the last refresh. They\'re treated as students.', ['rule' => $describe($missed['rule'])]) }}</p>
            @if ($missed['students'])
                <ul class="mt-2 divide-y divide-gray-200 dark:divide-gray-700 rounded-md border border-gray-200 dark:border-gray-700">
                    @foreach ($missed['students'] as $account)
                        <li class="px-3 py-2">
                            <p class="font-medium text-gray-900 dark:text-gray-100">{{ $account['name'] ?: $account['email'] }}
                                @if ($account['suspended'])<span class="ms-1 rounded bg-gray-100 dark:bg-gray-700 px-1.5 py-0.5 text-xs font-normal text-gray-700 dark:text-gray-300">{{ __('suspended') }}</span>@endif
                            </p>
                            <p class="break-all text-gray-600 dark:text-gray-400">{{ $account['email'] }}</p>
                            <p class="break-all text-gray-600 dark:text-gray-400">{{ __('In OU :ou. Caught by :rules.', ['ou' => $account['ou'] ?: '/', 'rules' => implode(__(' and '), array_map($describe, $account['caught_by']))]) }}</p>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

    <div class="flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('broker.log') }}" class="inline-flex min-h-11 items-center font-medium text-indigo-600 dark:text-indigo-400 underline">{{ __('What Claude ran') }}</a>
        <form method="POST" action="{{ route('broker.refresh') }}">
            @csrf
            <x-primary-button>{{ __('Refresh now') }}</x-primary-button>
        </form>
    </div>
</x-settings-card>
