@php use App\Support\Portal\Format; @endphp
<div>
    <x-portal.back :href="route('portal.schedule')">Schedule</x-portal.back>

    @if ($errorMessage)
        <h1 class="sr-only">Schedule</h1>
        <x-portal.error :message="$errorMessage" />
    @elseif ($entry)
        <h1 class="text-2xl font-semibold">{{ $entry['title'] }}</h1>
        <p class="text-muted">{{ Format::activityType($entry['activity_type'] ?? null) }}</p>

        <dl class="mt-6 rounded-xl border border-line bg-raised px-5">
            <x-portal.info-row label="When" :value="Format::entryWhen($entry, $offset)" />
            <x-portal.info-row label="Project" :value="$entry['project']['name'] ?? null" />
            <x-portal.info-row label="Organizer" :value="$entry['creator']['display_name'] ?? null" />
        </dl>

        @php($people = $entry['participants'] ?? [])
        <section aria-labelledby="entry-people" class="mt-6">
            <h2 id="entry-people" class="mb-2 font-semibold">People ({{ count($people) }})</h2>
            @if (count($people) === 0)
                <x-portal.notice>No other people.</x-portal.notice>
            @else
                <ul class="divide-y divide-line rounded-xl border border-line bg-raised">
                    @foreach ($people as $person)
                        <li wire:key="person-{{ $person['public_id'] }}" class="px-5 py-3">{{ $person['display_name'] }}</li>
                    @endforeach
                </ul>
            @endif
        </section>

        @if (filled($entry['description'] ?? null))
            <section aria-labelledby="entry-description" class="mt-6 rounded-xl border border-line bg-raised p-5">
                <h2 id="entry-description" class="mb-2 font-semibold">Description</h2>
                <p class="whitespace-pre-line">{{ $entry['description'] }}</p>
            </section>
        @endif
    @endif
</div>
