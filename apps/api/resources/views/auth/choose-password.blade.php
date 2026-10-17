<x-layouts.guest title="Choose your password">
    @livewire('account.change-password', ['forced' => true])
    <form method="POST" action="{{ route('logout') }}" class="mt-4 text-center">
        @csrf
        <button type="submit" class="btn btn-text text-sm">Log out</button>
    </form>
</x-layouts.guest>
