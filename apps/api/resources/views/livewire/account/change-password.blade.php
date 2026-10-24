<div>
    @if ($forced)
        <h1 class="mb-2 text-xl font-semibold">Choose your password</h1>
        <p class="mb-6 text-sm text-muted">You signed in with a temporary password. Choose your own to continue. You'll use it on the web and in the phone app.</p>
    @else
        <x-portal.back :href="route('portal.profile')">My profile</x-portal.back>
        <h1 class="sr-only">Change password</h1>
    @endif

    <form wire:submit="save" x-data="{ shown: {} }" x-on:submit="shown = {}" class="space-y-5 {{ $forced ? '' : 'card p-5' }}" novalidate>
        @if ($generalError)
            <p role="alert" class="font-medium text-danger">{{ $generalError }}</p>
        @endif

        @foreach ([
            ['currentPassword', $forced ? 'Temporary password' : 'Current password', 'current-password', null],
            ['password', 'New password', 'new-password', "At least {$minLength} characters. Not your email address."],
            ['passwordConfirmation', 'New password again', 'new-password', null],
        ] as [$field, $label, $autocomplete, $hint])
            <div>
                <label for="{{ $field }}" class="mb-1 block text-sm font-semibold">{{ $label }}</label>
                @if ($hint)
                    <p id="{{ $field }}-hint" class="mb-1 text-sm text-muted">{{ $hint }}</p>
                @endif
                <div class="flex gap-2">
                    <input id="{{ $field }}" type="password" wire:model="{{ $field }}" autocomplete="{{ $autocomplete }}"
                           @error($field) aria-invalid="true" @enderror
                           aria-describedby="{{ trim(($hint ? $field.'-hint ' : '').($errors->has($field) ? $field.'-error' : '')) }}"
                           x-bind:type="shown['{{ $field }}'] ? 'text' : 'password'" class="field w-full">
                    <button type="button" x-on:click="shown['{{ $field }}'] = ! shown['{{ $field }}']" aria-controls="{{ $field }}" class="btn btn-text btn-compact shrink-0"><span x-text="shown['{{ $field }}'] ? 'Hide' : 'Show'">Show</span><span class="sr-only"> {{ strtolower($label) }}</span></button>
                </div>
                @error($field) <p id="{{ $field }}-error" class="mt-1 text-sm font-medium text-danger">{{ $message }}</p> @enderror
            </div>
        @endforeach

        <div class="flex flex-wrap gap-2">
            <button type="submit" wire:loading.attr="disabled" wire:target="save"
                    class="btn btn-primary {{ $forced ? 'w-full' : '' }}">
                {{ $forced ? 'Set password and continue' : 'Change password' }}
            </button>
            @unless ($forced)
                <a href="{{ route('portal.profile') }}" class="btn btn-text">Cancel</a>
            @endunless
        </div>
    </form>
</div>
