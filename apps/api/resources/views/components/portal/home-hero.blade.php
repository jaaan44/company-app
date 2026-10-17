@props(['initials' => null, 'subtitle' => null])
{{--
    Home's hero (mobile redesign, docs/design/mobile-home-reference.png): the
    app's mark, the person's initials (a link to My profile) and the page's
    heading, on the navy-to-blue gradient. Edge to edge under the status bar
    on phones, where it replaces the app bar; a rounded panel from md up.
--}}
<div class="-mx-4 -mt-5 bg-linear-to-br from-hero-from to-hero-to px-5 pt-[calc(1.25rem+env(safe-area-inset-top))] pb-14 text-white md:mx-0 md:mt-0 md:rounded-3xl md:px-8 md:pt-7 md:pb-9">
    <div class="flex items-center justify-between gap-3">
        {{-- From md up the sidebar carries the app's mark. --}}
        <p class="flex min-w-0 items-center gap-3 text-lg font-semibold md:invisible">
            <img src="/icons/icon-192.png" alt="" class="size-10 shrink-0 rounded-xl">
            <span class="truncate">Company App</span>
        </p>
        @if (filled($initials))
            <a href="{{ route('portal.profile') }}" aria-label="My profile"
               class="inline-flex size-11 shrink-0 items-center justify-center rounded-full border-2 border-white/70 font-semibold hover:bg-white/10 focus-visible:outline-white">{{ $initials }}</a>
        @endif
    </div>
    <h1 class="mt-6 text-3xl leading-tight font-bold tracking-tight [overflow-wrap:anywhere]">{{ $slot }}</h1>
    @if (filled($subtitle))
        <p class="mt-1 text-hero-sub">{{ $subtitle }}</p>
    @endif
</div>
