@php use App\Support\Portal\Format; @endphp
<div>
    <x-portal.back :href="route('portal.announcements.manage')">Manage announcements</x-portal.back>

    @if ($errorMessage)
        <h1 class="sr-only">Announcement</h1>
        <x-portal.error :message="$errorMessage" />
    @elseif ($item)
        @php
            $status = $item['status'];
        @endphp
        <x-portal.icon-tile icon="announcements" tone="violet" size="lg" class="mb-3" />
        <h1 class="text-2xl font-bold tracking-tight">{{ $item['title'] }}</h1>
        <p class="mt-2 flex flex-wrap items-center gap-2">
            <x-portal.chip :tone="Format::tone($status)">{{ Format::announcementStatus($status) }}</x-portal.chip>
            <span class="text-sm text-muted">
                @if ($item['audience_type'] === 'scoped')
                    For: {{ collect([...($item['departments'] ?? []), ...($item['teams'] ?? [])])->pluck('name')->implode(', ') }}
                @else
                    For everyone
                @endif
            </span>
        </p>
        @if ($item['published_at'] ?? null)
            <p class="mt-1 text-sm text-muted">Published {{ Format::dateTime($item['published_at'], $utcOffset) }}@isset($item['acknowledgements_count']) · {{ $item['acknowledgements_count'] }} acknowledged @endisset</p>
        @endif

        <div class="mt-3 flex flex-wrap gap-2">
            @if ($status === 'draft' && $mayPublish)
                <x-portal.confirm title="Publish this announcement?" body="Everyone in its audience will see it and be notified. A published announcement can't be edited; archive it and write a new one instead." confirm="Publish" action="publish()"
                                  class="btn btn-primary">Publish</x-portal.confirm>
            @endif
            @if ($status === 'draft' && $mayEdit)
                <a href="{{ route('portal.announcements.manage.edit', $item['public_id']) }}" class="btn btn-secondary">Edit draft</a>
            @endif
            @if ($status === 'draft' && $mayDelete)
                <x-portal.confirm title="Delete this draft?" body="This can't be undone." confirm="Delete" action="delete()" :danger="true"
                                  class="btn btn-danger-outline">Delete draft</x-portal.confirm>
            @endif
            @if ($status === 'published' && $mayPublish)
                <x-portal.confirm title="Archive this announcement?" body="It will no longer show in staff's announcements." confirm="Archive" action="archive()"
                                  class="btn btn-secondary">Archive</x-portal.confirm>
            @endif
        </div>
        @if ($generalError)
            <p role="alert" class="mt-3 font-medium text-danger">{{ $generalError }}</p>
        @endif

        <section aria-label="Announcement text" class="mt-6 card p-5">
            <p class="whitespace-pre-line">{{ $item['body'] }}</p>
        </section>
        <x-portal.toast :message="$savedMessage" :nonce="$toastNonce" />
    @endif
</div>
