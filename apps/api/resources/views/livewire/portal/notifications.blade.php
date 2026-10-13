@php use App\Support\Portal\Format; @endphp
<div>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-semibold">Notifications</h1>
        @if (! $errorMessage && $hasUnread)
            <button type="button" wire:click="markAllRead" wire:loading.attr="disabled" wire:target="markAllRead"
                    class="inline-flex min-h-11 items-center rounded-md border border-line px-4 hover:bg-hover disabled:opacity-60">Mark all read</button>
        @endif
    </div>

    <div aria-live="polite">
        @if ($doneMessage)
            <p role="status" class="mb-4 rounded-md border border-line bg-raised p-3">{{ $doneMessage }}</p>
        @endif
        @if ($actionMessage)
            <p role="alert" class="mb-4 rounded-md bg-danger-soft p-3 font-medium text-danger">{{ $actionMessage }}</p>
        @endif
    </div>

    @if ($errorMessage)
        <x-portal.error :message="$errorMessage" />
    @elseif (count($notifications) === 0)
        <p class="rounded-lg border border-line bg-raised p-4">No notifications yet.</p>
    @else
        <ul class="divide-y divide-line rounded-xl border border-line bg-raised">
            @foreach ($notifications as $notification)
                @php($unread = $notification['read_at'] === null)
                <li wire:key="notification-{{ $notification['public_id'] }}">
                    <button type="button" wire:click="open('{{ $notification['public_id'] }}')"
                            class="flex min-h-11 w-full items-start justify-between gap-4 px-5 py-3 text-left hover:bg-hover">
                        <span class="min-w-0">
                            @if ($unread)<span class="sr-only">Unread: </span>@endif
                            <span class="block {{ $unread ? 'font-semibold' : '' }}">{{ $notification['title'] }}</span>
                            <span class="block text-sm">{{ $notification['message'] }}</span>
                            @if ($companyDay)
                                <span class="block text-sm text-muted">{{ Format::inboxTime($notification['created_at'], $companyDay['date'], $companyDay['utc_offset']) }}</span>
                            @endif
                        </span>
                        @if ($unread)
                            <span aria-hidden="true" class="mt-2 size-2.5 shrink-0 rounded-full bg-accent"></span>
                        @endif
                    </button>
                </li>
            @endforeach
        </ul>
        <x-portal.pager :meta="$meta" />
    @endif
</div>
