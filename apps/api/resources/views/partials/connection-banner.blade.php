{{--
    Connection banner (Phase 37, DEC-067): resources/js/app.js shows it when
    the phone goes offline or a request fails, and hides it again when the
    connection is back or a request succeeds. Never a browser pop-up.
--}}
<div id="connection-banner" role="status" aria-live="polite" hidden
     class="fixed inset-x-0 top-0 z-50 bg-danger px-4 pt-[calc(0.5rem+env(safe-area-inset-top))] pb-2 text-center text-sm font-medium text-raised shadow">
    <span data-offline>You're offline. Changes won't be saved until you reconnect.</span>
    <span data-error hidden>Something went wrong on our side. Please try again.</span>
</div>
