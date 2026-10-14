@props(['title' => 'Company App'])
@php
    // Phase 34F (F-1): the mobile app's five tabs; every other portal page belongs to More.
    $tabs = [
        ['portal.home', 'Home', 'home', ['portal.home']],
        ['portal.tasks', 'Tasks', 'tasks', ['portal.tasks', 'portal.tasks.*', 'portal.task']],
        ['portal.schedule', 'Schedule', 'schedule', ['portal.schedule', 'portal.schedule-entry']],
        ['portal.messages', 'Messages', 'messages', ['portal.messages', 'portal.messages.*', 'portal.chat']],
    ];
    $onTab = false;
    foreach ($tabs as $tab) {
        $onTab = $onTab || request()->routeIs(...$tab[3]);
    }
    $tabs[] = ['portal.more', 'More', 'more', $onTab ? ['__none__'] : ['portal.*']];

    // F-2: a page listed on More gets a back arrow to More, unless it sets its own.
    $back = trim($__env->yieldPushContent('appbar-back'));
    $backToMore = $back === '' && request()->routeIs(
        'portal.profile', 'portal.status', 'portal.announcements', 'portal.notifications', 'portal.reports',
        'portal.leave', 'portal.directory', 'portal.work-logs', 'portal.projects', 'portal.clients',
    );
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.app-head')
        <title>{{ $title }} — Company App</title>
    </head>
    <body class="min-h-screen bg-surface text-ink antialiased">
        <a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-md focus:bg-raised focus:px-4 focus:py-2 focus:shadow">Skip to content</a>

        {{-- Wider screens: the five tabs in a sidebar. --}}
        <aside class="fixed inset-y-0 left-0 z-20 hidden w-60 flex-col border-r border-line bg-raised md:flex">
            <a href="{{ route('portal.home') }}" class="flex min-h-16 items-center gap-3 px-6 text-lg font-semibold">
                <img src="/icons/icon-192.png" alt="" class="size-8 rounded-lg"> Company App
            </a>
            <nav aria-label="Main" class="flex-1 px-3">
                <ul class="space-y-1">
                    @foreach ($tabs as [$name, $label, $icon, $patterns])
                        @php
                            $current = request()->routeIs(...$patterns);
                        @endphp
                        <li>
                            <a href="{{ route($name) }}" @if ($current) aria-current="page" @endif
                               class="flex min-h-12 items-center gap-4 rounded-full px-4 {{ $current ? 'bg-accent-soft font-semibold text-accent-ink' : 'hover:bg-hover' }}">
                                <x-portal.icon :name="$icon" /> {{ $label }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            </nav>
            <div class="space-y-1 border-t border-line p-3">
                @can('admin.access')
                    <a href="{{ route('admin.dashboard') }}" data-no-navigate class="flex min-h-12 items-center gap-4 rounded-full px-4 hover:bg-hover"><x-portal.icon name="admin" /> Admin</a>
                @endcan
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="flex min-h-12 w-full items-center gap-4 rounded-full px-4 text-left hover:bg-hover"><x-portal.icon name="logout" /> Log out</button>
                </form>
            </div>
        </aside>

        <div class="md:pl-60">
            {{-- F-2: the app bar: the page title and, on a sub-page, a back arrow. --}}
            <header class="sticky top-0 z-20 border-b border-line bg-raised pt-[env(safe-area-inset-top)]">
                <div class="mx-auto flex min-h-14 max-w-3xl items-center gap-2 px-4">
                    @if ($back !== '')
                        {!! $back !!}
                    @elseif ($backToMore)
                        <a href="{{ route('portal.more') }}" class="-ml-2 inline-flex size-11 shrink-0 items-center justify-center rounded-full hover:bg-hover" aria-label="Back to More">
                            <x-portal.icon name="back" />
                        </a>
                    @endif
                    <p class="truncate text-lg font-semibold">{{ $title }}</p>
                </div>
            </header>

            <main id="main" class="mx-auto max-w-3xl px-4 pt-5 pb-[calc(6rem+env(safe-area-inset-bottom))] md:pb-12">
                {{ $slot }}
            </main>
        </div>

        {{-- Phones: the five tabs at the bottom, as in the app. --}}
        <nav aria-label="Main" class="fixed inset-x-0 bottom-0 z-30 border-t border-line bg-raised pb-[env(safe-area-inset-bottom)] md:hidden">
            <ul class="grid grid-cols-5">
                @foreach ($tabs as [$name, $label, $icon, $patterns])
                    @php
                        $current = request()->routeIs(...$patterns);
                    @endphp
                    <li>
                        <a href="{{ route($name) }}" @if ($current) aria-current="page" @endif
                           class="flex min-h-16 flex-col items-center justify-center gap-1 text-xs {{ $current ? 'font-semibold text-accent-ink' : 'text-muted' }}">
                            <span class="inline-flex h-8 w-14 items-center justify-center rounded-full {{ $current ? 'bg-accent-soft' : '' }}"><x-portal.icon :name="$icon" /></span>
                            {{ $label }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </nav>

        <x-portal.toast :message="session('status')" />
    </body>
</html>
