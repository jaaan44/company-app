@php use App\Support\Portal\Format; @endphp
<div>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <h1 class="sr-only">Messages</h1>
        @unless ($noProfile || $errorMessage)
            <x-portal.fab :href="route('portal.messages.new')" label="New message" />
        @endunless
    </div>

    @if ($noProfile)
        <x-portal.no-profile />
    @elseif ($errorMessage)
        <x-portal.error :message="$errorMessage" />
    @elseif (count($conversations) === 0)
        <x-portal.notice>No conversations yet.</x-portal.notice>
    @else
        <ul class="divide-y divide-line overflow-hidden card">
            @foreach ($conversations as $conversation)
                @php($unread = (int) ($conversation['unread_count'] ?? 0))
                <li wire:key="conversation-{{ $conversation['public_id'] }}">
                    @php($title = Format::conversationTitle($conversation, $myStaffId))
                    <a href="{{ route('portal.chat', $conversation['public_id']) }}" class="chevron flex min-h-16 items-center gap-3 px-4 py-3 hover:bg-hover">
                        {{-- 37D: a person's initials for a direct chat, a tile for a group or project chat. --}}
                        @if (($conversation['type'] ?? null) === 'direct')
                            <x-portal.avatar :name="$title" />
                        @else
                            <x-portal.icon-tile :icon="($conversation['type'] ?? null) === 'project' ? 'projects' : 'directory'" :tone="($conversation['type'] ?? null) === 'project' ? 'blue' : 'violet'" :round="true" />
                        @endif
                        <span class="block min-w-0 flex-1">
                            <span class="block truncate {{ $unread > 0 ? 'font-bold' : 'font-semibold' }}">{{ $title }}</span>
                            <span class="block truncate text-sm {{ $unread > 0 ? 'text-ink' : 'text-muted' }}">{{ Format::lastMessagePreview($conversation, $myStaffId) }}</span>
                        </span>
                        <span class="flex shrink-0 flex-col items-end gap-1">
                            @if (($conversation['last_message']['created_at'] ?? null) && $companyDay)
                                <span class="text-sm text-muted">{{ Format::inboxTime($conversation['last_message']['created_at'], $companyDay['date'], $companyDay['utc_offset']) }}</span>
                            @endif
                            @if ($unread > 0)
                                <span class="min-w-6 rounded-full bg-danger px-1.5 text-center text-sm leading-6 font-semibold text-raised tabular-nums">{{ $unread > 99 ? '99+' : $unread }}<span class="sr-only"> unread</span></span>
                            @endif
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
        <x-portal.pager :meta="$meta" />
    @endif
</div>
