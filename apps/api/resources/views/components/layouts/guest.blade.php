@props(['title' => 'Sign in'])
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
        <main class="mx-auto mt-[8vh] w-full max-w-md px-4">
            <div class="rounded-xl border border-line bg-raised p-6 shadow-sm sm:p-8">
                {{ $slot }}
            </div>
        </main>
    </body>
</html>
