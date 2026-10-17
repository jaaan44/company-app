@php use App\Support\Portal\Format; @endphp
<div>
    <h1 class="sr-only">Announcements</h1>
    @if ($mayManage)
        <p class="mb-4"><a href="{{ route('portal.announcements.manage') }}" class="btn btn-secondary">Manage announcements</a></p>
    @endif

    @if ($noProfile)
        <x-portal.no-profile />
    @elseif ($errorMessage)
        <x-portal.error :message="$errorMessage" />
    @elseif (count($announcements) === 0)
        <x-portal.notice>No announcements yet.</x-portal.notice>
    @else
        <ul class="divide-y divide-line rounded-xl border border-line bg-raised">
            @foreach ($announcements as $announcement)
                @php($new = ($announcement['acknowledged_at'] ?? null) === null)
                <li wire:key="announcement-{{ $announcement['public_id'] }}">
                    <a href="{{ route('portal.announcement', $announcement['public_id']) }}" class="chevron block min-h-11 px-5 py-3 hover:bg-hover">
                        <span class="block {{ $new ? 'font-semibold' : 'font-medium' }}">{{ $announcement['title'] }}</span>
                        @if (($announcement['published_at'] ?? null) && $companyDay)
                            <span class="block text-sm text-muted">{{ Format::date(\Carbon\CarbonImmutable::parse($announcement['published_at'])->setTimezone($companyDay['utc_offset'])->toDateString()) }}</span>
                        @endif
                        @if ($new)
                            <span class="mt-1 block"><x-portal.chip tone="info">New<span class="sr-only"> — not yet acknowledged</span></x-portal.chip></span>
                        @endif
                    </a>
                </li>
            @endforeach
        </ul>
        <x-portal.pager :meta="$meta" />
    @endif
</div>
