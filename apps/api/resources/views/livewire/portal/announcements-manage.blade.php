@php use App\Livewire\Portal\AnnouncementsManage; use App\Support\Portal\Format; @endphp
<div>
    <x-portal.back :href="route('portal.announcements')">Announcements</x-portal.back>
    <h1 class="sr-only">Manage announcements</h1>
    @if ($mayAdd)
        <x-portal.fab :href="route('portal.announcements.manage.new')" label="New announcement" />
    @endif

    <div role="group" aria-label="Which announcements" class="mb-4 inline-flex flex-wrap rounded-md border border-line bg-raised p-1">
        @foreach (AnnouncementsManage::STATUSES as $value => $label)
            <button type="button" wire:click="showStatus('{{ $value }}')" aria-pressed="{{ $current === $value ? 'true' : 'false' }}"
                    class="inline-flex min-h-11 items-center rounded px-4 {{ $current === $value ? 'bg-accent-soft font-semibold text-accent-ink' : 'hover:bg-hover' }}">{{ $label }}</button>
        @endforeach
    </div>

    @if ($errorMessage)
        <x-portal.error :message="$errorMessage" />
    @elseif (count($items) === 0)
        <p class="rounded-lg border border-line bg-raised p-4">No {{ strtolower(AnnouncementsManage::STATUSES[$current]) }}.</p>
    @else
        <ul class="divide-y divide-line rounded-xl border border-line bg-raised">
            @foreach ($items as $item)
                <li wire:key="manage-{{ $item['public_id'] }}">
                    <a href="{{ route('portal.announcements.manage.show', $item['public_id']) }}" class="chevron block min-h-11 px-5 py-3 hover:bg-hover">
                        <span class="block font-medium">{{ $item['title'] }}</span>
                        <span class="block text-sm text-muted">{{ $item['audience_type'] === 'scoped' ? 'Selected departments or teams' : 'Everyone' }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
        <x-portal.pager :meta="$meta" />
    @endif
</div>
