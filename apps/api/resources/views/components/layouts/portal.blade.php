@props(['title' => 'Company App'])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="color-scheme" content="light dark">
        <title>{{ $title }} — Company App</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-surface text-ink antialiased">
        <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-10 focus:rounded-md focus:bg-raised focus:px-4 focus:py-2 focus:shadow">Skip to content</a>

        <header class="border-b border-line bg-raised">
            <div class="mx-auto flex max-w-5xl flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3">
                <a href="{{ route('portal.home') }}" class="text-lg font-semibold">Company App</a>

                <nav aria-label="Main" class="order-3 w-full sm:order-none sm:w-auto">
                    <ul class="flex flex-wrap gap-1">
                        @foreach ([
                            ['portal.home', 'Home', ['portal.home']],
                            ['portal.directory', 'Staff directory', ['portal.directory', 'portal.staff']],
                            ['portal.profile', 'My profile', ['portal.profile']],
                        ] as [$name, $label, $patterns])
                            @php($current = request()->routeIs(...$patterns))
                            <li>
                                <a href="{{ route($name) }}"
                                   @if ($current) aria-current="page" @endif
                                   class="inline-flex min-h-11 items-center rounded-md px-3 hover:bg-hover {{ $current ? 'bg-accent-soft font-semibold text-accent-ink' : '' }}">
                                    {{ $label }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </nav>

                <div class="ml-auto flex items-center gap-2">
                    @can('admin.access')
                        <a href="{{ route('home') }}" class="inline-flex min-h-11 items-center rounded-md px-3 hover:bg-hover">Admin</a>
                    @endcan
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="inline-flex min-h-11 items-center rounded-md px-3 hover:bg-hover">Log out</button>
                    </form>
                </div>
            </div>
        </header>

        <main id="main" class="mx-auto max-w-5xl px-4 py-6">
            {{ $slot }}
        </main>
    </body>
</html>
