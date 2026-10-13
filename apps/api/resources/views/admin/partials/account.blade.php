{{-- Staff portal (B-6, a full page load: a different layout) and Log out. --}}
<div class="space-y-1 border-t border-line p-3">
    <a href="{{ route('portal.home') }}" data-no-navigate class="flex min-h-11 items-center gap-4 rounded-full px-4 hover:bg-hover"><x-portal.icon name="portal" /> Staff portal</a>
    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="flex min-h-11 w-full items-center gap-4 rounded-full px-4 text-left hover:bg-hover"><x-portal.icon name="logout" /> Log out</button>
    </form>
</div>
