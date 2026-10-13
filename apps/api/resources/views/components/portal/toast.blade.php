@props(['message' => null, 'nonce' => 0])
{{-- A short confirmation (Phase 34F, F-4): a toast above the tab bar that fades after a few seconds, announced as a status. --}}
@if (filled($message))
    <div wire:key="toast-{{ md5($message) }}-{{ $nonce }}" x-data="{ show: true }" x-init="setTimeout(() => show = false, 4500)" x-show="show" x-transition.opacity.duration.300ms
         role="status"
         class="fixed inset-x-4 bottom-[calc(5.5rem+env(safe-area-inset-bottom))] z-40 rounded-xl bg-ink px-4 py-3 text-surface shadow-lg md:inset-x-auto md:bottom-6 md:right-6 md:w-96">
        {{ $message }}
    </div>
@endif
