{{-- One labelled form field (Phase 35D): a text input or a textarea, with its error. --}}
@php
    $type = $type ?? 'text';
    $isTextarea = $textarea ?? false;
    $classes = 'field w-full';
@endphp
<div>
    <label for="{{ $id }}" class="mb-1 block text-sm font-semibold">{{ $label }} @if ($optional ?? false)<span class="font-normal text-muted">(optional)</span>@endif</label>
    @if ($isTextarea)
        <textarea id="{{ $id }}" wire:model="{{ $model }}" rows="{{ $rows ?? 4 }}" @if (isset($max)) maxlength="{{ $max }}" @endif
                  @error($error) aria-invalid="true" aria-describedby="{{ $id }}-error" @enderror class="{{ $classes }}"></textarea>
    @else
        <input id="{{ $id }}" type="{{ $type }}" wire:model="{{ $model }}" @if (isset($max)) maxlength="{{ $max }}" @endif autocomplete="off"
               @error($error) aria-invalid="true" aria-describedby="{{ $id }}-error" @enderror class="{{ $classes }}">
    @endif
    @error($error) <p id="{{ $id }}-error" class="mt-1 text-sm font-medium text-danger">{{ $message }}</p> @enderror
</div>
