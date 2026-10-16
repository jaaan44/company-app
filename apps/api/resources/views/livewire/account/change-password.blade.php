<div>
    @if ($forced)
        <h1 class="mb-2 text-xl font-semibold">Choose your password</h1>
        <p class="mb-6 text-sm text-muted">You signed in with a temporary password. Choose your own to continue. You'll use it on the web and in the phone app.</p>
    @else
        <x-portal.back :href="route('portal.profile')">My profile</x-portal.back>
        <h1 class="sr-only">Change password</h1>
    @endif

    <form wire:submit="save" class="space-y-5 {{ $forced ? '' : 'rounded-xl border border-line bg-raised p-5' }}" novalidate>
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
                <input id="{{ $field }}" type="password" wire:model="{{ $field }}" autocomplete="{{ $autocomplete }}"
                       @error($field) aria-invalid="true" @enderror
                       aria-describedby="{{ trim(($hint ? $field.'-hint ' : '').($errors->has($field) ? $field.'-error' : '')) }}"
                       class="min-h-11 w-full rounded-md border border-line bg-raised px-3">
                @error($field) <p id="{{ $field }}-error" class="mt-1 text-sm font-medium text-danger">{{ $message }}</p> @enderror
            </div>
        @endforeach

        <div class="flex flex-wrap gap-2">
            <button type="submit" wire:loading.attr="disabled" wire:target="save"
                    class="inline-flex min-h-11 items-center justify-center rounded-full bg-accent px-5 font-medium text-on-accent hover:opacity-90 disabled:opacity-60 {{ $forced ? 'w-full' : '' }}">
                {{ $forced ? 'Set password and continue' : 'Change password' }}
            </button>
            @unless ($forced)
                <a href="{{ route('portal.profile') }}" class="inline-flex min-h-11 items-center rounded-full px-5 font-medium text-accent-ink hover:bg-hover">Cancel</a>
            @endunless
        </div>
    </form>
</div>
