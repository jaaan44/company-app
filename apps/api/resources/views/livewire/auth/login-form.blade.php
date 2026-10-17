<div>
    <h1 class="mb-6 text-xl font-semibold">Sign in to Company App</h1>

    @if (session('status'))
        <p role="status" class="mb-4 rounded-md border border-line bg-surface p-3 text-sm">{{ session('status') }}</p>
    @elseif (session('url.intended'))
        <p role="status" class="mb-4 rounded-md border border-line bg-surface p-3 text-sm">Please sign in to continue.</p>
    @endif

    @error('email')
        <p role="alert" class="mb-4 text-sm font-medium text-danger">{{ $message }}</p>
    @enderror

    {{-- Phase 37B: a password can be shown to check it; it's hidden again on Sign in, which matches what the server renders. --}}
    <form wire:submit="login" x-data="{ shown: false }" x-on:submit="shown = false" class="space-y-4">
        <div>
            <label for="email" class="mb-1 block text-sm font-semibold">Email</label>
            <input type="email" id="email" wire:model="email" autocomplete="username" inputmode="email" autocapitalize="none" spellcheck="false" enterkeyhint="next" required autofocus
                   class="field w-full">
        </div>

        <div>
            <label for="password" class="mb-1 block text-sm font-semibold">Password</label>
            <div class="flex gap-2">
                <input type="password" id="password" wire:model="password" autocomplete="current-password" enterkeyhint="go" required
                       x-bind:type="shown ? 'text' : 'password'" class="field w-full">
                <button type="button" x-on:click="shown = ! shown" aria-controls="password" class="btn btn-text btn-compact shrink-0"><span x-text="shown ? 'Hide' : 'Show'">Show</span><span class="sr-only"> password</span></button>
            </div>
        </div>

        <label class="flex min-h-11 items-center gap-3">
            <input type="checkbox" wire:model="remember" class="size-5 accent-[var(--color-accent)]">
            <span>Keep me signed in on this device <span class="block text-sm text-muted">For 30 days. Leave it off on a shared phone or computer.</span></span>
        </label>

        <button type="submit" wire:loading.attr="disabled" wire:target="login"
                class="btn btn-primary w-full">
            <span wire:loading.remove wire:target="login">Sign in</span>
            <span wire:loading wire:target="login">Signing in…</span>
        </button>
    </form>
</div>
