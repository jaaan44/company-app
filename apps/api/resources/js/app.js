/*
 * Staff Web Portal (Phase 34F, F-5): move between portal pages the way an
 * app does — without a full page load — using Livewire's own navigation
 * (`Livewire.navigate`, the engine behind `wire:navigate`). Only plain,
 * same-origin links into /app (and, from Phase 35B, /admin) are taken over; downloads, new tabs,
 * modified clicks and links marked `data-no-navigate` behave normally.
 */
document.addEventListener('click', (event) => {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
        return;
    }
    const link = event.target.closest('a[href]');
    if (!link || link.target || link.hasAttribute('download') || link.hasAttribute('data-no-navigate')) {
        return;
    }
    const url = new URL(link.href, window.location.href);
    const samePage = url.pathname === window.location.pathname && url.search === window.location.search && url.hash !== '';
    if (url.origin !== window.location.origin || samePage || !/^\/(app|admin)(\/|$)/.test(url.pathname) || url.pathname.includes('/files/')) {
        return;
    }
    if (typeof window.Livewire?.navigate !== 'function') {
        return;
    }
    event.preventDefault();
    window.Livewire.navigate(url.href);
});
