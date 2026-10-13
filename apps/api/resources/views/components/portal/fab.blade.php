@props(['href', 'label'])
{{-- A page's main action (Phase 34F, F-3): a button in the page on wider screens, a round "+" above the tab bar on phones. --}}
<a href="{{ $href }}" class="hidden min-h-11 items-center gap-2 rounded-full bg-accent px-5 font-medium text-on-accent hover:opacity-90 md:inline-flex">
    <x-portal.icon name="add" class="size-5" /> {{ $label }}
</a>
<a href="{{ $href }}" aria-label="{{ $label }}"
   class="fixed right-4 bottom-[calc(5.5rem+env(safe-area-inset-bottom))] z-30 inline-flex size-14 items-center justify-center rounded-2xl bg-accent text-on-accent shadow-lg hover:opacity-90 md:hidden">
    <x-portal.icon name="add" class="size-7" />
</a>
