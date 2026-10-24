@php use App\Livewire\Portal\ServiceReportForm as F; @endphp
<div>
    <x-portal.back :href="$publicId ? route('portal.service-report', $publicId) : route('portal.reports')">{{ $publicId ? 'Service report' : 'Reports' }}</x-portal.back>
    <h1 class="sr-only">{{ $publicId === null ? 'New service report' : 'Edit service report' }}</h1>

    @if ($noProfile)
        <x-portal.no-profile />
    @elseif ($notFound)
        <x-portal.notice>This draft is no longer available.</x-portal.notice>
    @elseif ($errorMessage)
        <x-portal.error :message="$errorMessage" />
    @else
        <form wire:submit="save" class="space-y-5 card p-5" novalidate>
            @if ($generalError)
                <p role="alert" class="font-medium text-danger">{{ $generalError }}</p>
            @endif

            <fieldset>
                <legend class="mb-1 text-sm font-semibold">Client</legend>
                @if ($client !== '')
                    <p class="flex flex-wrap items-center gap-3">
                        <span class="font-medium">{{ $clientName }}</span>
                        <button type="button" wire:click="changeClient" class="btn btn-text btn-compact">Change client</button>
                    </p>
                @else
                    <label for="client-search" class="sr-only">Search clients</label>
                    <input id="client-search" type="search" wire:model.live.debounce.400ms="clientSearch" placeholder="Search clients" autocomplete="off"
                           @error('client') aria-invalid="true" aria-describedby="client-error" @enderror
                           class="field w-full">
                    @if (trim($clientSearch) !== '')
                        @if (count($clients) === 0)
                            <p class="mt-2 text-muted">No clients found.</p>
                        @else
                            <ul class="mt-2 divide-y divide-line rounded-xl border border-edge">
                                @foreach ($clients as $c)
                                    <li wire:key="client-{{ $c['public_id'] }}">
                                        <button type="button" wire:click="chooseClient('{{ $c['public_id'] }}')" class="block min-h-11 w-full px-3 py-2 text-left hover:bg-hover">
                                            {{ $c['name'] }}@if ($c['client_code'] ?? null) <span class="text-muted">({{ $c['client_code'] }})</span>@endif
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    @else
                        <p class="mt-1 text-sm text-muted">Type part of the client's name.</p>
                    @endif
                @endif
                @error('client') <p id="client-error" class="mt-1 text-sm font-medium text-danger">{{ $message }}</p> @enderror
            </fieldset>

            <div>
                <label for="project" class="mb-1 block text-sm font-semibold">Project (optional)</label>
                <select id="project" wire:model="project" @disabled($client === '')
                        @error('project') aria-invalid="true" aria-describedby="project-error" @enderror
                        class="field w-full">
                    <option value="">{{ $client === '' ? 'Choose a client first' : 'No project' }}</option>
                    @foreach ($projects as $p)
                        <option value="{{ $p['public_id'] }}">{{ $p['name'] }}{{ $p['project_code'] ? ' ('.$p['project_code'].')' : '' }}</option>
                    @endforeach
                </select>
                @error('project') <p id="project-error" class="mt-1 text-sm font-medium text-danger">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="service-date" class="mb-1 block text-sm font-semibold">Service date</label>
                <input id="service-date" type="date" wire:model="serviceDate" max="{{ $companyDate }}"
                       @error('serviceDate') aria-invalid="true" aria-describedby="service-date-error" @enderror
                       class="field">
                @error('serviceDate') <p id="service-date-error" class="mt-1 text-sm font-medium text-danger">{{ $message }}</p> @enderror
            </div>

            @include('livewire.portal.partials.report-field', ['id' => 'work-performed', 'label' => 'Work performed', 'model' => 'workPerformed', 'error' => 'workPerformed', 'max' => F::MAX_LONG, 'rows' => 5])
            @include('livewire.portal.partials.report-field', ['id' => 'findings', 'label' => 'Findings (optional)', 'model' => 'findings', 'error' => 'findings', 'max' => F::MAX_TEXT])
            @include('livewire.portal.partials.report-field', ['id' => 'recommendations', 'label' => 'Recommendations (optional)', 'model' => 'recommendations', 'error' => 'recommendations', 'max' => F::MAX_TEXT])
            @include('livewire.portal.partials.report-field', ['id' => 'follow-up', 'label' => 'Follow-up actions (optional)', 'model' => 'followUpActions', 'error' => 'followUpActions', 'max' => F::MAX_TEXT])
            @include('livewire.portal.partials.report-field', ['id' => 'site-rep', 'label' => 'Site representative (optional)', 'model' => 'siteRepresentative', 'error' => 'siteRepresentative', 'input' => 'text', 'max' => F::MAX_SHORT])

            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" wire:loading.attr="disabled" wire:target="save"
                        class="btn btn-primary">{{ $publicId === null ? 'Save draft' : 'Save changes' }}</button>
                <a href="{{ $publicId ? route('portal.service-report', $publicId) : route('portal.reports') }}" class="btn btn-plain">Cancel</a>
            </div>
            <p class="text-sm text-muted">It's saved as a draft. You can add photos, then submit it for review.</p>
        </form>
    @endif
</div>
