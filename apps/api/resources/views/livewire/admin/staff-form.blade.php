@php use App\Livewire\Admin\StaffForm; @endphp
<div>
    @if ($publicId === null)
        <x-portal.back :href="route('admin.staff')">Staff records</x-portal.back>
    @else
        <x-portal.back :href="route('admin.staff.show', $publicId)">Staff record</x-portal.back>
    @endif
    <h1 class="sr-only">{{ $publicId === null ? 'New staff record' : 'Edit staff record' }}</h1>

    @if ($notFound)
        <p class="rounded-lg border border-line bg-raised p-4">This staff record no longer exists.</p>
    @elseif ($errorMessage)
        <x-portal.error :message="$errorMessage" />
    @else
        @php($select = 'min-h-11 w-full rounded-md border border-line bg-raised px-3')
        <form wire:submit="save" class="space-y-6 rounded-xl border border-line bg-raised p-5" novalidate>
            @if ($generalError)
                <p role="alert" class="font-medium text-danger">{{ $generalError }}</p>
            @endif

            <fieldset class="grid gap-5 sm:grid-cols-2">
                <legend class="mb-2 font-semibold">Person</legend>
                @foreach (StaffForm::FIELDS as $field => [$property, $label, $max, $type])
                    @include('livewire.portal.partials.field', [
                        'id' => 'staff-'.$property, 'label' => $label, 'model' => $property, 'error' => $property, 'max' => $max,
                        'type' => $type, 'optional' => ! in_array($field, ['employee_number', 'first_name', 'last_name'], true),
                    ])
                @endforeach
            </fieldset>

            <fieldset class="grid gap-5 sm:grid-cols-2">
                <legend class="mb-2 font-semibold">Employment</legend>
                <div>
                    <label for="staff-status" class="mb-1 block text-sm font-semibold">Status</label>
                    <select id="staff-status" wire:model.live="status" class="{{ $select }}" @error('status') aria-invalid="true" aria-describedby="staff-status-error" @enderror>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                        <option value="separated">Separated</option>
                    </select>
                    @error('status') <p id="staff-status-error" class="mt-1 text-sm font-medium text-danger">{{ $message }}</p> @enderror
                </div>
                @include('livewire.portal.partials.field', ['id' => 'staff-hireDate', 'label' => 'Hire date', 'model' => 'hireDate', 'error' => 'hireDate', 'type' => 'date', 'optional' => true])
                @if ($status === 'separated')
                    @include('livewire.portal.partials.field', ['id' => 'staff-separationDate', 'label' => 'Separation date', 'model' => 'separationDate', 'error' => 'separationDate', 'type' => 'date'])
                @endif
            </fieldset>

            <fieldset class="grid gap-5 sm:grid-cols-2">
                <legend class="mb-2 font-semibold">Organisation</legend>
                @foreach ([
                    ['departmentId', 'Department', $departments, 'name'],
                    ['teamId', 'Team', $teams, 'name'],
                    ['positionId', 'Position', $positions, 'title'],
                ] as [$property, $label, $options, $nameKey])
                    <div>
                        <label for="staff-{{ $property }}" class="mb-1 block text-sm font-semibold">{{ $label }} <span class="font-normal text-muted">(optional)</span></label>
                        <select id="staff-{{ $property }}" wire:model="{{ $property }}" class="{{ $select }}"
                                @error($property) aria-invalid="true" aria-describedby="staff-{{ $property }}-error" @enderror>
                            <option value="">None</option>
                            @foreach ($options as $option)
                                <option value="{{ $option['public_id'] }}">
                                    {{ $option[$nameKey] }}@if ($property !== 'departmentId' && ($option['department'] ?? null)) ({{ $option['department']['name'] }})@endif{{ $option['status'] === 'inactive' ? ' — inactive' : '' }}
                                </option>
                            @endforeach
                        </select>
                        @error($property) <p id="staff-{{ $property }}-error" class="mt-1 text-sm font-medium text-danger">{{ $message }}</p> @enderror
                    </div>
                @endforeach

                <div class="sm:col-span-2">
                    <label for="manager-search" class="mb-1 block text-sm font-semibold">Manager <span class="font-normal text-muted">(optional)</span></label>
                    @if ($managerId !== '')
                        <p class="flex flex-wrap items-center gap-3">
                            <span class="font-medium">{{ $managerName }}</span>
                            <button type="button" wire:click="clearManager" class="inline-flex min-h-11 items-center rounded-md px-3 text-accent-ink hover:bg-hover">Remove<span class="sr-only"> {{ $managerName }} as manager</span></button>
                        </p>
                    @endif
                    <input id="manager-search" type="search" wire:model.live.debounce.400ms="managerSearch" autocomplete="off"
                           placeholder="{{ $managerId === '' ? 'Search by name' : 'Search to change' }}"
                           @error('managerSearch') aria-invalid="true" aria-describedby="manager-error" @enderror
                           class="min-h-11 w-full rounded-md border border-line bg-raised px-3 sm:max-w-md">
                    @error('managerSearch') <p id="manager-error" class="mt-1 text-sm font-medium text-danger">{{ $message }}</p> @enderror
                    @if ($searchFailed)
                        <p class="mt-1 text-sm text-muted">The staff directory couldn't be searched.</p>
                    @elseif (mb_strlen(trim($managerSearch)) >= 2)
                        @if (count($people) === 0)
                            <p class="mt-1 text-sm text-muted">No one matches "{{ trim($managerSearch) }}".</p>
                        @else
                            <ul class="mt-2 divide-y divide-line rounded-md border border-line sm:max-w-md">
                                @foreach ($people as $person)
                                    <li wire:key="manager-{{ $person['public_id'] }}">
                                        <button type="button" wire:click="chooseManager(@js($person['public_id']), @js($person['display_name']))"
                                                class="block min-h-11 w-full px-3 py-2 text-left hover:bg-hover">
                                            {{ $person['display_name'] }} <span class="text-sm text-muted">{{ $person['employee_number'] }}</span>
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    @endif
                </div>
            </fieldset>

            <div class="flex flex-wrap gap-2">
                <button type="submit" wire:loading.attr="disabled" wire:target="save"
                        class="inline-flex min-h-11 items-center rounded-full bg-accent px-5 font-medium text-on-accent hover:opacity-90 disabled:opacity-60">
                    {{ $publicId === null ? 'Add staff record' : 'Save changes' }}
                </button>
                <a href="{{ $publicId === null ? route('admin.staff') : route('admin.staff.show', $publicId) }}" class="inline-flex min-h-11 items-center rounded-full px-5 font-medium text-accent-ink hover:bg-hover">Cancel</a>
            </div>
        </form>
    @endif
</div>
