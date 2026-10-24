@php use App\Support\Portal\Format; @endphp
<div>
    <x-portal.back :href="route('portal.announcements')">Announcements</x-portal.back>

    @if ($noProfile)
        <x-portal.no-profile />
    @elseif ($errorMessage)
        <h1 class="sr-only">Announcement</h1>
        @if ($errorMessage === 'This announcement is no longer available.')
            <x-portal.notice>{{ $errorMessage }}</x-portal.notice>
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
        <article class="card p-5">
            <x-portal.icon-tile icon="announcements" tone="violet" size="lg" class="mb-3" />
            <h1 class="text-2xl font-bold tracking-tight">{{ $announcement['title'] }}</h1>
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
                            class="btn btn-primary">Acknowledge</button>
                @endif
            </div>
        </article>
    @endif
</div>
