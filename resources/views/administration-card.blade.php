@php
    $broker = app(\TornadoEngine\Broker\Broker::class);
    $notReady = $broker->notReady();
    $counts = \TornadoEngine\Broker\Models\Person::query()->selectRaw('kind, count(*) as total')->groupBy('kind')->orderBy('kind')->pluck('total', 'kind');
    $report = json_decode(\App\Models\Setting::get('broker.report') ?? '[]', true);
    $refreshed = \App\Models\Setting::get('broker.refreshed_at');
    $box = 'rounded-md border px-4 py-3';
@endphp

<x-settings-card :title="__('Broker')" wide>
    <p class="text-gray-600 dark:text-gray-400">{{ \TornadoEngine\Broker\Broker::about() }}</p>
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

    @if ($counts->isNotEmpty())
        <div>
            <h4 class="font-medium text-gray-900 dark:text-gray-100">{{ __('Pseudonyms') }}</h4>
            <div class="mt-2 flex flex-wrap gap-2">
                @foreach ($counts as $kind => $total)
                    <code class="rounded bg-gray-100 dark:bg-gray-900 px-2 py-1 font-mono text-xs text-gray-700 dark:text-gray-300">{{ 'p-…@'.$kind.' · '.number_format($total) }}</code>
                @endforeach
            </div>
        </div>
    @endif

    @if ($notReady)
        <form method="POST" action="{{ route('broker.roles') }}" class="flex justify-end">
            @csrf
            <x-primary-button>{{ __('Load now') }}</x-primary-button>
        </form>
    @endif

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

    <a href="{{ route('broker.log') }}" class="inline-flex min-h-11 items-center font-medium text-indigo-600 dark:text-indigo-400 underline">{{ __('What Claude ran') }}</a>
</x-settings-card>
