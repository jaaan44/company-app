{{--
    One labelled form field (Phase 34E): $id, $label, $model, $error key, $input ('textarea' or an input type), optional $max and $hint.
    Phase 37: the parameter was `$type`, which a component's own `$type` property (the incident form's) silently overrode, so long fields rendered as one-line inputs.
--}}
<div>
    <label for="{{ $id }}" class="mb-1 block text-sm font-semibold">{{ $label }}</label>
    @if (($input ?? 'textarea') === 'textarea')
        <textarea id="{{ $id }}" wire:model="{{ $model }}" rows="{{ $rows ?? 3 }}" @isset($max) maxlength="{{ $max }}" @endisset
                  @error($error) aria-invalid="true" aria-describedby="{{ $id }}-error" @enderror
                  class="w-full rounded-md border border-line bg-raised px-3 py-2"></textarea>
    @else
        <input id="{{ $id }}" type="{{ $input }}" wire:model="{{ $model }}" @isset($max) maxlength="{{ $max }}" @endisset
               @error($error) aria-invalid="true" aria-describedby="{{ $id }}-error" @enderror
               class="min-h-11 w-full rounded-md border border-line bg-raised px-3">
    @endif
    @error($error) <p id="{{ $id }}-error" class="mt-1 text-sm font-medium text-danger">{{ $message }}</p> @enderror
</div>
