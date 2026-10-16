@php use App\Support\Portal\Format; @endphp
<div>
    <x-portal.back :href="route('admin.service-reports')">Service reports</x-portal.back>

    @if ($notFound)
        <h1 class="sr-only">Service report</h1>
        <p class="rounded-lg border border-line bg-raised p-4">This report no longer exists.</p>
    @elseif ($errorMessage && ! $report)
        <h1 class="sr-only">Service report</h1>
        <x-portal.error :message="$errorMessage" />
    @elseif ($report)
        <h1 class="text-xl font-semibold">{{ $report['client']['name'] }} · {{ Format::date($report['service_date']) }}</h1>
        <p class="mt-2"><x-portal.chip :tone="Format::reportTone($report['status'])">{{ Format::serviceStatus($report['status']) }}</x-portal.chip></p>

        @if ($generalError)
            <p role="alert" class="mt-4 rounded-md bg-danger-soft p-3 font-medium text-danger">{{ $generalError }}</p>
        @endif

        <section aria-labelledby="details" class="mt-6 rounded-xl border border-line bg-raised px-5">
            <h2 id="details" class="pt-4 font-semibold">Details</h2>
            <dl>
                <x-portal.info-row label="Written by" :value="$report['creator']['display_name'] ?? null" />
                <x-portal.info-row label="Project" :value="$report['project']['name'] ?? null" />
                <x-portal.info-row label="Task" :value="$report['task']['title'] ?? null" />
                <x-portal.info-row label="Site representative" :value="$report['site_representative_name']" />
                @foreach (['work_performed' => 'Work performed', 'findings' => 'Findings', 'recommendations' => 'Recommendations', 'follow_up_actions' => 'Follow-up actions'] as $field => $label)
                    @if (filled($report[$field]))
                        <div class="border-b border-line py-3 last:border-0">
                            <dt class="text-sm text-muted">{{ $label }}</dt>
                            <dd class="mt-1 whitespace-pre-line">{{ $report[$field] }}</dd>
                        </div>
                    @endif
                @endforeach
            </dl>
        </section>

        @if ($report['status'] === 'submitted')
            <section aria-label="Review" class="mt-6 rounded-xl border border-line bg-raised p-5">
                @if ($confirming === null)
                    <div class="flex flex-wrap gap-3">
                        <button type="button" wire:click="ask('review')" class="inline-flex min-h-11 items-center rounded-full bg-accent px-5 font-medium text-on-accent hover:opacity-90">Mark reviewed</button>
                        <button type="button" wire:click="ask('reject')" class="inline-flex min-h-11 items-center rounded-full border border-line px-5 font-medium text-danger hover:bg-hover">Reject</button>
                    </div>
                @else
                    <form wire:submit="confirm" novalidate x-data x-init="$nextTick(() => $refs.text.focus())">
                        <h2 class="font-semibold">{{ $confirming === 'review' ? 'Mark this report as reviewed?' : 'Reject this report?' }}</h2>
                        <p class="mt-1 text-sm text-muted">{{ $confirming === 'review' ? 'It becomes final, and the writer sees it as Reviewed.' : 'The writer can return it to draft, correct it and submit again.' }}</p>
                        <label for="confirm-text" class="mb-1 mt-3 block text-sm font-semibold">{{ $confirming === 'review' ? 'Note (optional)' : 'Reason' }}</label>
                        <textarea id="confirm-text" x-ref="text" wire:model="text" rows="3" maxlength="1000"
                                  @error('text') aria-invalid="true" aria-describedby="text-error" @enderror
                                  class="w-full rounded-md border border-line bg-raised px-3 py-2"></textarea>
                        @error('text') <p id="text-error" class="mt-1 text-sm font-medium text-danger">{{ $message }}</p> @enderror
                        <div class="mt-3 flex flex-wrap gap-3">
                            <button type="submit" class="inline-flex min-h-11 items-center rounded-full px-5 font-medium {{ $confirming === 'review' ? 'bg-accent text-on-accent' : 'bg-danger text-raised' }} hover:opacity-90">{{ $confirming === 'review' ? 'Mark reviewed' : 'Reject' }}</button>
                            <button type="button" wire:click="dismiss" class="inline-flex min-h-11 items-center rounded-full px-5 font-medium text-accent-ink hover:bg-hover">Back</button>
                        </div>
                    </form>
                @endif
            </section>
        @endif

        @include('livewire.admin.partials.report-files-history', ['kind' => 'service'])
        <x-portal.toast :message="$savedMessage" :nonce="$toastNonce" />
    @endif
</div>
