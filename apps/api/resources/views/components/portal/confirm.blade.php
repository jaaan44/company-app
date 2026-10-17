@props(['title', 'body' => null, 'confirm', 'cancel' => 'Cancel', 'action', 'danger' => false])
{{--
    An in-app confirmation (Phase 34F, F-4), replacing the browser's own
    dialog: the trigger opens a modal <dialog> (a sheet from the bottom on
    phones), Cancel closes it, and the confirm button calls the Livewire
    action ($action, e.g. "deleteDraft" or "removeFile('…')").
--}}
@php($dialogId = 'confirm-'.substr(md5($title.$action), 0, 10))
<div x-data class="contents">
    <button type="button" x-on:click="$refs.dialog.showModal()" {{ $attributes }}>{{ $slot }}</button>
    <dialog x-ref="dialog" aria-labelledby="{{ $dialogId }}-title" class="app-dialog bg-raised p-6 text-ink shadow-xl">
        <h2 id="{{ $dialogId }}-title" class="text-lg font-semibold">{{ $title }}</h2>
        @if (filled($body))
            <p class="mt-2 text-muted">{{ $body }}</p>
        @endif
        <div class="mt-6 flex flex-wrap justify-end gap-2">
            <button type="button" x-on:click="$refs.dialog.close()" autofocus class="btn btn-text">{{ $cancel }}</button>
            <button type="button" x-on:click="$refs.dialog.close(); $wire.{{ $action }}"
                    class="btn {{ $danger ? 'btn-danger' : 'btn-primary' }}">{{ $confirm }}</button>
        </div>
    </dialog>
</div>
