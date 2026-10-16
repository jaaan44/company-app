{{--
    A temporary password, shown once (Phase 36A, DEC-066 M-3). It is in
    this page only: never in the session, a URL or a log.
--}}
<div class="rounded-xl border border-line bg-accent-soft p-5 text-accent-ink" role="status">
    <p class="font-semibold">{{ $heading }}</p>
    <p class="mt-1 text-sm">Give this temporary password to {{ $account['name'] }} yourself. <strong>It won't be shown again.</strong> They sign in at the web with {{ $account['email'] }} and choose their own password; the phone app works after that.</p>
    <div class="mt-3 flex flex-wrap items-center gap-3" x-data="{ copied: false }">
        <code class="rounded-md border border-line bg-raised px-3 py-2 font-mono text-lg tracking-wide text-ink" id="temporary-password">{{ $password }}</code>
        <button type="button" x-on:click="navigator.clipboard?.writeText(@js($password)).then(() => copied = true)"
                class="inline-flex min-h-11 items-center rounded-full border border-line bg-raised px-4 font-medium hover:bg-hover">
            <span x-show="! copied">Copy</span><span x-show="copied" x-cloak>Copied</span>
        </button>
    </div>
</div>
