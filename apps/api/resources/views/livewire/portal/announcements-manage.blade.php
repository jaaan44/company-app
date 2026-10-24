@php use App\Livewire\Portal\AnnouncementsManage; use App\Support\Portal\Format; @endphp
<div>
    <x-portal.back :href="route('portal.announcements')">Announcements</x-portal.back>
    <h1 class="sr-only">Manage announcements</h1>
    @if ($mayAdd)
        <x-portal.fab :href="route('portal.announcements.manage.new')" label="New announcement" />
    @endif

    <div role="group" aria-label="Which announcements" class="mb-4 inline-flex flex-wrap gap-2">
        @foreach (AnnouncementsManage::STATUSES as $value => $label)
            <button type="button" wire:click="showStatus('{{ $value }}')" aria-pressed="{{ $current === $value ? 'true' : 'false' }}"
                    class="filter-chip">{{ $label }}</button>
        @endforeach
    </div>

    @if ($errorMessage)
        <x-portal.error :message="$errorMessage" />
    @elseif (count($items) === 0)
        <x-portal.notice>No {{ strtolower(AnnouncementsManage::STATUSES[$current]) }}.</x-portal.notice>
    @else
        <ul class="divide-y divide-line overflow-hidden card">
            @foreach ($items as $item)
                <li wire:key="manage-{{ $item['public_id'] }}">
                    <a href="{{ route('portal.announcements.manage.show', $item['public_id']) }}" class="chevron flex min-h-16 items-start gap-3 px-4 py-3 hover:bg-hover">
                        <x-portal.icon-tile icon="announcements" :tone="Format::tileTone(Format::tone($item['status'] ?? null))" />
                        <span class="block min-w-0 flex-1">
                            <span class="block font-semibold [overflow-wrap:anywhere]">{{ $item['title'] }}</span>
                            <span class="block text-sm text-muted">{{ $item['audience_type'] === 'scoped' ? 'Selected departments or teams' : 'Everyone' }}</span>
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
        <x-portal.pager :meta="$meta" />
    @endif
</div>
