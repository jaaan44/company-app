@php use App\Support\Portal\Format; @endphp
<div>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <h1 class="sr-only">Messages</h1>
        @unless ($noProfile || $errorMessage)
            <x-portal.fab :href="route('portal.messages.new')" label="New message" />
        @endunless
    </div>

    @if ($noProfile)
        <p class="rounded-lg border border-line bg-raised p-4">No staff profile is linked to this account.</p>
    @elseif ($errorMessage)
        <x-portal.error :message="$errorMessage" />
    @elseif (count($conversations) === 0)
        <p class="rounded-lg border border-line bg-raised p-4">No conversations yet.</p>
    @else
        <ul class="divide-y divide-line rounded-xl border border-line bg-raised">
            @foreach ($conversations as $conversation)
                @php($unread = (int) ($conversation['unread_count'] ?? 0))
                <li wire:key="conversation-{{ $conversation['public_id'] }}">
                    <a href="{{ route('portal.chat', $conversation['public_id']) }}" class="chevron flex min-h-11 items-start justify-between gap-4 px-5 py-3 hover:bg-hover">
                        <span class="min-w-0">
                            <span class="block truncate {{ $unread > 0 ? 'font-semibold' : 'font-medium' }}">{{ Format::conversationTitle($conversation, $myStaffId) }}</span>
                            <span class="block truncate text-sm text-muted">{{ Format::lastMessagePreview($conversation, $myStaffId) }}</span>
                        </span>
                        <span class="flex shrink-0 flex-col items-end gap-1">
                            @if (($conversation['last_message']['created_at'] ?? null) && $companyDay)
                                <span class="text-sm text-muted">{{ Format::inboxTime($conversation['last_message']['created_at'], $companyDay['date'], $companyDay['utc_offset']) }}</span>
                            @endif
                            @if ($unread > 0)
                                <span class="rounded-full bg-accent px-2 text-sm font-semibold text-on-accent">{{ $unread > 99 ? '99+' : $unread }}<span class="sr-only"> unread</span></span>
                            @endif
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
        <x-portal.pager :meta="$meta" />
    @endif
</div>
