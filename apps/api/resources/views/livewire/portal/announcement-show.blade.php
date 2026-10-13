@php use App\Support\Portal\Format; @endphp
<div>
    <x-portal.back :href="route('portal.announcements')">Announcements</x-portal.back>

    @if ($noProfile)
        <p class="rounded-lg border border-line bg-raised p-4">No staff profile is linked to this account.</p>
    @elseif ($errorMessage)
        <h1 class="mb-4 text-2xl font-semibold">Announcement</h1>
        @if ($errorMessage === 'This announcement is no longer available.')
            <p class="rounded-lg border border-line bg-raised p-4">{{ $errorMessage }}</p>
        @else
            <x-portal.error :message="$errorMessage" />
        @endif
    @elseif ($announcement)
        @php
            $offset = $companyDay['utc_offset'] ?? '+00:00';
            $by = implode(' · ', array_filter([
                $announcement['published_by']['display_name'] ?? null,
                ($announcement['published_at'] ?? null) ? Format::dateTime($announcement['published_at'], $offset) : null,
            ]));
        @endphp
        <article class="rounded-xl border border-line bg-raised p-5">
            <h1 class="text-2xl font-semibold">{{ $announcement['title'] }}</h1>
            @if ($by !== '')
                <p class="mt-1 text-muted">{{ $by }}</p>
            @endif
            <div class="mt-4 whitespace-pre-line break-words">{{ $announcement['body'] }}</div>

            <div class="mt-6" aria-live="polite">
                @if ($actionMessage)
                    <p role="alert" class="mb-3 font-medium text-danger">{{ $actionMessage }}</p>
                @endif
                @if ($announcement['acknowledged_at'] ?? null)
                    <p role="status" class="font-medium">✓ Acknowledged on {{ Format::dateTime($announcement['acknowledged_at'], $offset) }}</p>
                @else
                    <button type="button" wire:click="acknowledge" wire:loading.attr="disabled" wire:target="acknowledge"
                            class="inline-flex min-h-11 items-center rounded-md bg-accent px-5 font-medium text-on-accent hover:opacity-90 disabled:opacity-60">Acknowledge</button>
                @endif
            </div>
        </article>
    @endif
</div>
