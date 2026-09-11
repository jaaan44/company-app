<x-layouts.app title="Company App — Admin">
    <h1>Company App — Admin</h1>
    <p>Signed in as {{ auth()->user()->name }} ({{ auth()->user()->email }}).</p>
    <p>This is a neutral authenticated placeholder. The Admin Dashboard itself is built in a later phase.</p>

    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit">Log out</button>
    </form>
</x-layouts.app>
