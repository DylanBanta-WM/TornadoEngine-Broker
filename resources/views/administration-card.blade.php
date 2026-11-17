@php
    $broker = app(\TornadoEngine\Broker\Broker::class);
    $notReady = $broker->notReady();
    $roles = \TornadoEngine\Access\Models\Role::orderBy('name')->get();
    $chosen = \TornadoEngine\Broker\Broker::pseudonymRoleIds();
    $counts = \TornadoEngine\Broker\Models\Person::query()->selectRaw('kind, count(*) as total')->groupBy('kind')->orderBy('kind')->pluck('total', 'kind');
    $report = json_decode(\App\Models\Setting::get('broker.report') ?? '[]', true);
    $refreshed = \App\Models\Setting::get('broker.refreshed_at');
    $box = 'rounded-md border px-4 py-3';
@endphp

<x-settings-card :title="__('Broker')">
    @if ($notReady)
        <p class="{{ $box }} border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-950/40 text-amber-900 dark:text-amber-200">{{ $notReady }}</p>
    @else
        <p class="{{ $box }} border-green-300 dark:border-green-800 bg-green-50 dark:bg-green-900/30 text-green-800 dark:text-green-300">
            {{ trans_choice('Ready: :count person given a pseudonym, refreshed :time.|Ready: :count people given pseudonyms, refreshed :time.', $counts->sum(), ['count' => number_format($counts->sum()), 'time' => \Illuminate\Support\Carbon::parse($refreshed)->diffForHumans()]) }}
        </p>
    @endif
    @if (session('broker_status'))
        <p class="{{ $box }} border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/40">{{ session('broker_status') }}</p>
    @endif
    @if ($errors->has('broker'))
        <p class="{{ $box }} border-red-300 dark:border-red-800 bg-red-50 dark:bg-red-900/30 text-red-800 dark:text-red-300">{{ $errors->first('broker') }}</p>
    @endif

    <form method="POST" action="{{ route('broker.roles') }}" class="space-y-3">
        @csrf
        <fieldset>
            <legend class="font-medium text-gray-900 dark:text-gray-100">{{ __('Roles given a pseudonym') }}</legend>
            <div class="mt-2 space-y-2">
                @foreach ($roles as $role)
                    <label class="flex min-h-11 items-center justify-between gap-3 rounded-md border border-gray-200 dark:border-gray-700 px-3 has-[:checked]:border-indigo-500 has-[:checked]:bg-indigo-50 dark:has-[:checked]:bg-indigo-950/40">
                        <span class="flex items-center gap-3">
                            <input type="checkbox" name="roles[]" value="{{ $role->id }}" class="rounded text-indigo-600 border-gray-300 dark:border-gray-700 dark:bg-gray-900 focus:ring-indigo-500" @checked(in_array($role->id, $chosen, true))>
                            <span class="text-gray-900 dark:text-gray-100">{{ $role->name }}</span>
                        </span>
                        @php($slug = \TornadoEngine\Broker\Pseudonym::slug($role->name))
                        <code class="break-all font-mono text-xs text-gray-500 dark:text-gray-400">{{ 'p-…@'.$slug.(isset($counts[$slug]) ? ' · '.number_format($counts[$slug]) : '') }}</code>
                    </label>
                @endforeach
            </div>
        </fieldset>
        <div class="flex justify-end">
            <x-secondary-button type="submit">{{ __('Save') }}</x-secondary-button>
        </div>
    </form>

    @if ($report)
        <div>
            <h4 class="font-medium text-gray-900 dark:text-gray-100">{{ __('Other identifying values replaced') }}</h4>
            <ul class="mt-1 space-y-0.5">
                @foreach ($report as $field => $count)
                    <li><code class="font-mono">{{ $field }}</code>: {{ number_format($count) }}</li>
                @endforeach
            </ul>
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
