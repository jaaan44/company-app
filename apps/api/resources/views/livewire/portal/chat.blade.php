@php
    use App\Support\Portal\Format;
    use App\Livewire\Portal\Chat;
    use Carbon\CarbonImmutable;
@endphp
<div wire:poll.15s>
    <x-portal.back :href="route('portal.messages')">Messages</x-portal.back>

    @if ($noProfile)
        <p class="rounded-lg border border-line bg-raised p-4">No staff profile is linked to this account.</p>
    @elseif ($errorMessage)
        <h1 class="sr-only">Conversation</h1>
        @if ($errorMessage === 'This conversation is no longer available.' || $errorMessage === "You don't have access to this conversation.")
            <p class="rounded-lg border border-line bg-raised p-4">{{ $errorMessage }}</p>
        @else
            <x-portal.error :message="$errorMessage" />
        @endif
    @elseif ($conversation)
        <h1 class="mb-4 text-2xl font-semibold">{{ Format::conversationTitle($conversation, $myStaffId) }}</h1>

        <section aria-label="Messages" class="rounded-xl border border-line bg-raised p-4">
            @if ($hasEarlier)
                <div class="mb-3 text-center">
                    <button type="button" wire:click="loadEarlier" class="inline-flex min-h-11 items-center rounded-md px-4 text-accent-ink hover:bg-hover">Load earlier messages</button>
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
                            <div class="max-w-[85%] rounded-lg px-3 py-2 {{ $mine ? 'bg-accent-soft text-ink' : 'border border-line bg-surface' }}">
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

        <form wire:submit="send" class="mt-4 rounded-xl border border-line bg-raised p-4" novalidate>
            <label for="message-body" class="mb-1 block text-sm font-semibold">Message</label>
            <textarea id="message-body" wire:model="body" rows="3" maxlength="{{ Chat::MAX_BODY }}"
                      @if ($sendError) aria-invalid="true" aria-describedby="send-error" @endif
                      class="w-full rounded-md border border-line bg-raised px-3 py-2"></textarea>
            @if ($sendError)
                <p id="send-error" role="alert" class="mt-1 text-sm font-medium text-danger">{{ $sendError }}</p>
            @endif
            <div class="mt-2 flex items-center gap-3">
                <button type="submit" wire:loading.attr="disabled" wire:target="send"
                        class="inline-flex min-h-11 items-center rounded-md bg-accent px-5 font-medium text-on-accent hover:opacity-90 disabled:opacity-60">Send</button>
                <span wire:loading wire:target="send" class="text-sm text-muted">Sending…</span>
            </div>
        </form>
    @endif
</div>
