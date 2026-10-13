<div>
    <h1 class="mb-6 text-xl font-semibold">Sign in to Company App</h1>

    @if (session('status'))
        <p role="status" class="mb-4 rounded-md border border-line bg-surface p-3 text-sm">{{ session('status') }}</p>
    @endif

    @error('email')
        <p role="alert" class="mb-4 text-sm font-medium text-danger">{{ $message }}</p>
    @enderror

    <form wire:submit="login" class="space-y-4">
        <div>
            <label for="email" class="mb-1 block text-sm font-semibold">Email</label>
            <input type="email" id="email" wire:model="email" autocomplete="username" required autofocus
                   class="min-h-11 w-full rounded-md border border-line bg-raised px-3">
        </div>

        <div>
            <label for="password" class="mb-1 block text-sm font-semibold">Password</label>
            <input type="password" id="password" wire:model="password" autocomplete="current-password" required
                   class="min-h-11 w-full rounded-md border border-line bg-raised px-3">
        </div>

        <button type="submit" wire:loading.attr="disabled" wire:target="login"
                class="inline-flex min-h-11 w-full items-center justify-center rounded-md bg-accent px-4 font-medium text-on-accent hover:opacity-90 disabled:opacity-60">
            <span wire:loading.remove wire:target="login">Sign in</span>
            <span wire:loading wire:target="login">Signing in…</span>
        </button>
    </form>
</div>
