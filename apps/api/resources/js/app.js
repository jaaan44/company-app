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

/*
 * Phase 37 (DEC-067 H-3, offline): no browser pop-ups and no silent taps.
 * - Offline (the phone's `offline` event, or a request that never reached
 *   the server): the connection banner says so until it's back.
 * - A server error (5xx) on a Livewire request: the banner says so, in
 *   place of Livewire's own error modal.
 * - An expired page (419) or session (401): reload instead of Livewire's
 *   "This page has expired" browser dialog. If the session is gone, the
 *   reload lands on the sign-in page, which brings the person back here.
 */
const banner = () => document.getElementById('connection-banner');
function showBanner(kind) {
    const el = banner();
    if (!el) {
        return;
    }
    el.querySelector('[data-offline]').hidden = kind !== 'offline';
    el.querySelector('[data-error]').hidden = kind !== 'error';
    el.hidden = false;
}
function hideBanner() {
    const el = banner();
    if (el) {
        el.hidden = true;
    }
}
window.addEventListener('offline', () => showBanner('offline'));
window.addEventListener('online', hideBanner);
document.addEventListener('livewire:navigated', () => {
    if (!navigator.onLine) {
        showBanner('offline');
    }
});

document.addEventListener('livewire:init', () => {
    window.Livewire.interceptRequest(({ onError, onFailure, onSuccess }) => {
        onError(({ response, preventDefault }) => {
            if (response.status === 419 || response.status === 401) {
                preventDefault();
                window.location.reload();
            } else if (response.status >= 500) {
                preventDefault();
                showBanner('error');
            }
        });
        onFailure(() => showBanner('offline'));
        onSuccess(() => {
            if (navigator.onLine) {
                hideBanner();
            }
        });
    });
});
