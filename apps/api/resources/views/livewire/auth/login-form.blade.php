<div>
    <h1>Company App — Admin sign in</h1>

    @error('email')
        <div class="error">{{ $message }}</div>
    @enderror

    <form wire:submit="login">
        <label for="email">Email</label>
        <input type="email" id="email" wire:model="email" autocomplete="username" required autofocus>

        <label for="password">Password</label>
        <input type="password" id="password" wire:model="password" autocomplete="current-password" required>

        <button type="submit" wire:loading.attr="disabled" wire:target="login">
            <span wire:loading.remove wire:target="login">Sign in</span>
            <span wire:loading wire:target="login">Signing in…</span>
        </button>
    </form>
</div>
