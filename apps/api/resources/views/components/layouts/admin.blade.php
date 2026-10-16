@props(['title' => 'Admin'])
@php
    // Phase 35B (B-2): the Admin Backoffice shell. Desktop-first: a sidebar from md up,
    // and the same list in a drawer on phones. Sections show only to those who may use them.
    $sections = \App\Support\Admin\Sections::visibleTo(auth()->user());
    $currentKey = match (true) {
        request()->routeIs('admin.dashboard') => 'dashboard',
        request()->routeIs('admin.roles', 'admin.users', 'admin.audit-log') => 'administration',
        request()->routeIs('admin.section') => request()->route('section'),
        default => null,
    };
    $links = [['dashboard', route('admin.dashboard'), 'Dashboard', 'dashboard']];
    foreach ($sections as $key => $section) {
        $links[] = [$key, route('admin.section', $key), $section['title'], $section['icon']];
    }
    $back = trim($__env->yieldPushContent('appbar-back'));
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.app-head')
        <title>{{ $title }} — Company App Admin</title>
    </head>
    <body class="min-h-screen bg-surface text-ink antialiased">
        @include('partials.connection-banner')
        <a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-md focus:bg-raised focus:px-4 focus:py-2 focus:shadow">Skip to content</a>

        {{-- Wider screens: the sections in a sidebar. --}}
        <aside class="fixed inset-y-0 left-0 z-20 hidden w-64 flex-col border-r border-line bg-raised md:flex">
            <a href="{{ route('admin.dashboard') }}" class="flex min-h-16 items-center gap-3 px-6 text-lg font-semibold">
                <img src="/icons/icon-192.png" alt="" class="size-8 rounded-lg"> <span>Company App <span class="block text-xs font-medium text-muted">Admin</span></span>
            </a>
            @include('admin.partials.nav', ['class' => 'flex-1 overflow-y-auto px-3'])
            @include('admin.partials.account')
        </aside>

        <div class="md:pl-64">
            <header class="sticky top-0 z-20 border-b border-line bg-raised pt-[env(safe-area-inset-top)]">
                <div class="mx-auto flex min-h-14 max-w-5xl items-center gap-2 px-4" x-data>
                    <button type="button" x-on:click="$refs.drawer.showModal()" class="-ml-2 inline-flex size-11 shrink-0 items-center justify-center rounded-full hover:bg-hover md:hidden" aria-label="Menu">
                        <x-portal.icon name="menu" />
                    </button>
                    @if ($back !== '')
                        {!! $back !!}
                    @endif
                    <p class="truncate text-lg font-semibold">{{ $title }}</p>

                    {{-- Phones: the same list in a drawer (a modal <dialog>, like the 34F confirmations). --}}
                    <dialog x-ref="drawer" aria-label="Menu" class="admin-drawer bg-raised text-ink shadow-xl">
                        <div class="flex min-h-14 items-center justify-between gap-2 border-b border-line px-4">
                            <span class="font-semibold">Company App Admin</span>
                            <button type="button" x-on:click="$refs.drawer.close()" autofocus class="inline-flex size-11 items-center justify-center rounded-full hover:bg-hover" aria-label="Close menu">
                                <x-portal.icon name="close" />
                            </button>
                        </div>
                        @include('admin.partials.nav', ['class' => 'px-3 py-2'])
                        @include('admin.partials.account')
                    </dialog>
                </div>
            </header>

            <main id="main" class="mx-auto max-w-5xl px-4 pt-5 pb-12">
                {{ $slot }}
            </main>
        </div>

        <x-portal.toast :message="session('status')" />
    </body>
</html>
