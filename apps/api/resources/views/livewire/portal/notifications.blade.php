@php use App\Support\Portal\Format; @endphp
<div>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <h1 class="sr-only">Notifications</h1>
        @if (! $errorMessage && $hasUnread)
            <button type="button" wire:click="markAllRead" wire:loading.attr="disabled" wire:target="markAllRead"
                    class="btn btn-secondary">Mark all read</button>
        @endif
    </div>

    <div aria-live="polite">
        <x-portal.toast :message="$doneMessage" :nonce="$toastNonce ?? 0" />
        @if ($actionMessage)
            <p role="alert" class="mb-4 rounded-xl bg-danger-soft p-3 font-medium text-danger">{{ $actionMessage }}</p>
        @endif
    </div>

    @if ($errorMessage)
        <x-portal.error :message="$errorMessage" />
    @elseif (count($notifications) === 0)
        <x-portal.notice>No notifications yet.</x-portal.notice>
    @else
        <ul class="divide-y divide-line overflow-hidden card">
            @foreach ($notifications as $notification)
                <li wire:key="notification-{{ $notification['public_id'] }}">
                    @php
                        $unread = $notification['read_at'] === null;
                        [$icon, $tone] = match ($notification['type'] ?? null) {
                            'announcement_published' => ['announcements', 'violet'],
                            'message_received' => ['messages', 'blue'],
                            default => ['notifications', 'green'],
                        };
                    @endphp
                    <button type="button" wire:click="open('{{ $notification['public_id'] }}')"
                            class="flex min-h-16 w-full items-start gap-3 px-4 py-3 text-left hover:bg-hover">
                        <x-portal.icon-tile :icon="$icon" :tone="$tone" />
                        <span class="block min-w-0 flex-1">
                            @if ($unread)<span class="sr-only">Unread: </span>@endif
                            <span class="block {{ $unread ? 'font-bold' : 'font-semibold' }} [overflow-wrap:anywhere]">{{ $notification['title'] }}</span>
                            <span class="block text-sm text-muted [overflow-wrap:anywhere]">{{ $notification['message'] }}</span>
                        </span>
                        <span class="flex shrink-0 flex-col items-end gap-2">
                            @if ($companyDay)
                                <span class="text-sm text-muted">{{ Format::inboxTime($notification['created_at'], $companyDay['date'], $companyDay['utc_offset']) }}</span>
                            @endif
                            @if ($unread)
                                <span aria-hidden="true" class="size-2.5 rounded-full bg-accent"></span>
                            @endif
                        </span>
                    </button>
                </li>
            @endforeach
        </ul>
        <x-portal.pager :meta="$meta" />
    @endif
</div>
