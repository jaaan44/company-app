<x-layouts.guest title="Choose your password">
    @livewire('account.change-password', ['forced' => true])
    <form method="POST" action="{{ route('logout') }}" class="mt-4 text-center">
        @csrf
        <button type="submit" class="inline-flex min-h-11 items-center rounded-full px-4 text-sm font-medium text-accent-ink hover:bg-hover">Log out</button>
    </form>
</x-layouts.guest>
