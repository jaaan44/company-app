@php
    use App\Support\Portal\Format;
    use App\Livewire\Portal\Chat;
    use Carbon\CarbonImmutable;
@endphp
{{--
    Phase 37B (DEC-068): the conversation opens at its latest message, and
    follows new ones (a poll, or your own) while you're near the end; reading
    earlier messages isn't interrupted. The message box stays in view.
--}}
<div wire:poll.15s
     x-data="{ nearEnd: true, toEnd() { window.scrollTo(0, document.documentElement.scrollHeight) } }"
     x-init="$nextTick(() => toEnd()); if ($refs.thread) new MutationObserver(() => nearEnd && toEnd()).observe($refs.thread, { childList: true, subtree: true })"
     x-on:scroll.window.throttle.100ms="nearEnd = window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 160">
    <x-portal.back :href="route('portal.messages')">Messages</x-portal.back>

    @if ($noProfile)
        <x-portal.no-profile />
    @elseif ($errorMessage)
        <h1 class="sr-only">Conversation</h1>
        @if ($errorMessage === 'This conversation is no longer available.' || $errorMessage === "You don't have access to this conversation.")
            <x-portal.notice>{{ $errorMessage }}</x-portal.notice>
        @else
            <x-portal.error :message="$errorMessage" />
        @endif
    @elseif ($conversation)
        <h1 class="mb-4 text-2xl font-bold tracking-tight">{{ Format::conversationTitle($conversation, $myStaffId) }}</h1>

        <section aria-label="Messages" x-ref="thread" class="card p-4">
            @if ($hasEarlier)
                <div class="mb-3 text-center">
                    <button type="button" wire:click="loadEarlier" class="btn btn-text">Load earlier messages</button>
                </div>
            @endif
            @if (count($messages) === 0)
                <p class="text-muted">No messages yet. Say hello.</p>
            @else
                <ol class="space-y-2" aria-live="polite">
                    @php
                        $lastDay = null;
                    @endphp
                    @foreach ($messages as $message)
                        @php
                            $at = $companyDay ? CarbonImmutable::parse($message['created_at'])->setTimezone($companyDay['utc_offset']) : CarbonImmutable::parse($message['created_at']);
                            $day = $at->toDateString();
                            $mine = ($message['sender']['public_id'] ?? null) === $myStaffId;
                        @endphp
                        @if ($companyDay && $day !== $lastDay)
                            <li class="py-2 text-center text-sm font-semibold text-muted" wire:key="day-{{ $day }}">{{ Format::chatDay($day, $companyDay['date']) }}</li>
                            @php
                                $lastDay = $day;
                            @endphp
                        @endif
                        <li wire:key="message-{{ $message['public_id'] }}" class="flex {{ $mine ? 'justify-end' : 'justify-start' }}">
                            <div class="max-w-[85%] rounded-2xl px-3.5 py-2 {{ $mine ? 'rounded-br-md bg-accent-soft text-ink' : 'rounded-bl-md border border-edge bg-surface' }}">
                                <p class="text-xs text-muted">
                                    <span class="{{ $mine || ! Format::showsSenderNames($conversation) ? 'sr-only' : 'font-semibold' }}">{{ $mine ? 'You' : $message['sender']['name'] }}{{ $mine || ! Format::showsSenderNames($conversation) ? ', ' : ' · ' }}</span>{{ $at->format('H:i') }}
                                </p>
                                <p class="whitespace-pre-line break-words">{{ $message['body'] }}</p>
                            </div>
                        </li>
                    @endforeach
                </ol>
            @endif
        </section>

        <form wire:submit="send" x-on:submit="nearEnd = true" class="sticky bottom-[calc(4.5rem+env(safe-area-inset-bottom))] z-10 mt-4 card p-4 md:bottom-4" novalidate>
            <label for="message-body" class="mb-1 block text-sm font-semibold">Message</label>
            <textarea id="message-body" wire:model="body" rows="2" maxlength="{{ Chat::MAX_BODY }}"
                      @if ($sendError) aria-invalid="true" aria-describedby="send-error" @endif
                      class="field w-full"></textarea>
            @if ($sendError)
                <p id="send-error" role="alert" class="mt-1 text-sm font-medium text-danger">{{ $sendError }}</p>
            @endif
            <div class="mt-2 flex items-center gap-3">
                <button type="submit" wire:loading.attr="disabled" wire:target="send"
                        class="btn btn-primary">Send</button>
                <span wire:loading wire:target="send" class="text-sm text-muted">Sending…</span>
            </div>
        </form>
    @endif
</div>
