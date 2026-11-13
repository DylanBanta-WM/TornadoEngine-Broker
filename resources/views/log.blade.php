<x-admin-layout :title="__('What Claude ran')">
    <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('Every command Claude ran through the broker, newest first, with the real addresses it used. Claude only saw pseudonyms.') }}</p>

    <section class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg divide-y divide-gray-200 dark:divide-gray-700">
        @forelse ($entries as $entry)
            <div class="px-6 py-3 text-sm">
                <p class="text-gray-500 dark:text-gray-400">{{ $entry->created_at->timezone(config('app.timezone'))->format('M j, g:i a') }} ·
                    <span @class(['text-green-700 dark:text-green-400' => $entry->ok, 'text-red-700 dark:text-red-400' => ! $entry->ok])>{{ $entry->ok ? __('worked') : __('failed') }}</span></p>
                <code class="block break-all font-mono text-gray-900 dark:text-gray-100">{{ $entry->tool }} {{ implode(' ', array_map(fn ($a) => str_contains($a, ' ') ? '"'.$a.'"' : $a, $entry->command)) }}</code>
            </div>
        @empty
            <p class="px-6 py-4 text-sm text-gray-600 dark:text-gray-400">{{ __('Claude hasn\'t run anything yet.') }}</p>
        @endforelse
    </section>
</x-admin-layout>
