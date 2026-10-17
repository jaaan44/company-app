{{--
    Error pages (Phase 37, DEC-067 H-4): phone-first, in the app's look, with
    one way back. Self-contained: no database, session or Livewire, so they
    still render when the app itself is failing.
--}}
<!DOCTYPE html>
<html lang="en">
    <head>
        @include('partials.app-head')
        <title>{{ $title }} — Company App</title>
    </head>
    <body class="min-h-screen bg-surface text-ink antialiased">
        <main class="mx-auto mt-[12vh] w-full max-w-md px-4 text-center">
            <p class="mb-6 flex items-center justify-center gap-3 text-xl font-semibold"><img src="/icons/icon-192.png" alt="" class="size-10 rounded-xl"> Company App</p>
            <div class="rounded-2xl border border-line bg-raised p-6 shadow-sm sm:p-8">
                <h1 class="text-xl font-semibold">{{ $title }}</h1>
                <p class="mt-3 text-muted">{{ $message }}</p>
                <a href="{{ $href ?? '/app' }}" data-no-navigate class="btn btn-primary mt-6 w-full">{{ $action ?? 'Go to Home' }}</a>
            </div>
        </main>
    </body>
</html>
