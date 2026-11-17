<x-admin-layout :title="__('Administration')">
    <x-administration-tabs current="broker" />

    <div>
        <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('What Claude ran') }}</h3>
        <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('Every command Claude ran through the broker, newest first, with the real addresses it used. Claude only saw pseudonyms.') }}</p>
    </div>

    <section class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg text-sm">
        @if ($entries->isNotEmpty())
            <div class="hidden grid-cols-[9rem_6rem_minmax(0,1fr)] gap-4 border-b border-gray-200 dark:border-gray-700 px-6 py-3 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 md:grid">
                <span>{{ __('When') }}</span>
                <span>{{ __('Result') }}</span>
                <span>{{ __('Command') }}</span>
            </div>
        @endif
        <div class="divide-y divide-gray-200 dark:divide-gray-700">
            @forelse ($entries as $entry)
                <div class="grid grid-cols-[auto_1fr] gap-x-2 gap-y-1 px-6 py-3 md:grid-cols-[9rem_6rem_minmax(0,1fr)] md:gap-x-4">
                    <span class="text-gray-500 dark:text-gray-400">{{ $entry->created_at->timezone(config('app.timezone'))->format('M j, g:i a') }}<span class="md:hidden"> ·</span></span>
                    <span @class(['text-green-700 dark:text-green-400' => $entry->ok, 'text-red-700 dark:text-red-400' => ! $entry->ok])>{{ $entry->ok ? __('worked') : __('failed') }}</span>
                    <code class="col-span-2 break-all font-mono text-gray-900 dark:text-gray-100 md:col-span-1">{{ $entry->tool }} {{ implode(' ', array_map(fn ($a) => str_contains($a, ' ') ? '"'.$a.'"' : $a, $entry->command)) }}</code>
                </div>
            @empty
                <p class="px-6 py-4 text-gray-600 dark:text-gray-400">{{ __('Claude hasn\'t run anything yet.') }}</p>
            @endforelse
        </div>
    </section>
</x-admin-layout>
