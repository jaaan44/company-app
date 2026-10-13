@props(['title' => 'Sign in'])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.app-head')
        <title>{{ $title }} — Company App</title>
    </head>
    <body class="min-h-screen bg-surface text-ink antialiased">
        <main class="mx-auto mt-[8vh] w-full max-w-md px-4">
            <p class="mb-6 flex items-center justify-center gap-3 text-xl font-semibold"><img src="/icons/icon-192.png" alt="" class="size-10 rounded-xl"> Company App</p>
            <div class="rounded-2xl border border-line bg-raised p-6 shadow-sm sm:p-8">
                {{ $slot }}
            </div>
        </main>
    </body>
</html>
