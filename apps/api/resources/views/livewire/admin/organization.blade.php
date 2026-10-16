@php use App\Livewire\Admin\Organization; @endphp
<div>
    <x-portal.back :href="route('admin.section', 'people')">People</x-portal.back>
    <h1 class="sr-only">Departments, teams and positions</h1>

    @if ($errorMessage)
        <x-portal.error :message="$errorMessage" />
    @else
        @php([$singular, $nameField] = Organization::TABS[$current])
        <div role="group" aria-label="Show" class="mb-4 inline-flex flex-wrap rounded-full border border-line bg-raised p-1">
            @foreach (Organization::TABS as $key => $_)
                <button type="button" wire:click="$set('tab', '{{ $key }}')" aria-pressed="{{ $current === $key ? 'true' : 'false' }}"
                        class="min-h-11 rounded-full px-4 font-medium {{ $current === $key ? 'bg-accent text-on-accent' : 'text-accent-ink hover:bg-hover' }}">{{ ucfirst($key) }}</button>
            @endforeach
        </div>

        @if ($generalError && $editing === null)
            <p role="alert" class="mb-4 font-medium text-danger">{{ $generalError }}</p>
        @endif

        @if ($canManage && $editing === null)
            <button type="button" wire:click="add" class="mb-4 inline-flex min-h-11 items-center gap-2 rounded-full bg-accent px-5 font-medium text-on-accent hover:opacity-90">
                <x-portal.icon name="add" class="size-5" /> New {{ $singular }}
            </button>
        @endif

        @if ($editing !== null)
            <form wire:submit="save" class="mb-6 space-y-5 rounded-xl border border-line bg-raised p-5" novalidate>
                <h2 class="font-semibold">{{ $editing === '' ? 'New '.$singular : 'Edit '.$singular }}</h2>
                @if ($generalError)
                    <p role="alert" class="font-medium text-danger">{{ $generalError }}</p>
                @endif
                <div class="grid gap-5 sm:grid-cols-2">
                    @include('livewire.portal.partials.field', ['id' => 'org-name', 'label' => $nameField === 'title' ? 'Title' : 'Name', 'model' => 'name', 'error' => 'name', 'max' => 255])
                    @if ($current !== 'departments')
                        <div>
                            <label for="org-department" class="mb-1 block text-sm font-semibold">Department <span class="font-normal text-muted">(optional)</span></label>
                            <select id="org-department" wire:model="departmentId" class="min-h-11 w-full rounded-md border border-line bg-raised px-3"
                                    @error('departmentId') aria-invalid="true" aria-describedby="org-department-error" @enderror>
                                <option value="">None</option>
                                @foreach ($departments as $department)
                                    <option value="{{ $department['public_id'] }}">{{ $department['name'] }}</option>
                                @endforeach
                            </select>
                            @error('departmentId') <p id="org-department-error" class="mt-1 text-sm font-medium text-danger">{{ $message }}</p> @enderror
                        </div>
                    @endif
                    <div>
                        <label for="org-status" class="mb-1 block text-sm font-semibold">Status</label>
                        <select id="org-status" wire:model="status" class="min-h-11 w-full rounded-md border border-line bg-raised px-3">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                    @include('livewire.portal.partials.field', ['id' => 'org-sort', 'label' => 'Order in lists', 'model' => 'sortOrder', 'error' => 'sortOrder', 'type' => 'number'])
                </div>
                @include('livewire.portal.partials.field', ['id' => 'org-description', 'label' => 'Description', 'model' => 'description', 'error' => 'description', 'textarea' => true, 'rows' => 3, 'optional' => true])
                <div class="flex flex-wrap gap-2">
                    <button type="submit" class="inline-flex min-h-11 items-center rounded-full bg-accent px-5 font-medium text-on-accent hover:opacity-90">{{ $editing === '' ? 'Add '.$singular : 'Save changes' }}</button>
                    <button type="button" wire:click="cancel" class="inline-flex min-h-11 items-center rounded-full px-5 font-medium text-accent-ink hover:bg-hover">Cancel</button>
                </div>
            </form>
        @endif

        @if ($items === [])
            <x-portal.empty>No {{ $current }} yet.</x-portal.empty>
        @else
            <ul class="divide-y divide-line overflow-hidden rounded-xl border border-line bg-raised">
                @foreach ($items as $item)
                    <li wire:key="{{ $current }}-{{ $item['public_id'] }}" class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 px-5 py-3">
                        <span class="min-w-0 flex-1 basis-56">
                            <span class="block font-medium">{{ $item[$nameField] }}</span>
                            <span class="block text-sm text-muted">
                                @if ($current === 'departments')
                                    {{ $item['teams_count'] ?? 0 }} {{ ($item['teams_count'] ?? 0) === 1 ? 'team' : 'teams' }} · {{ $item['positions_count'] ?? 0 }} {{ ($item['positions_count'] ?? 0) === 1 ? 'position' : 'positions' }}
                                @else
                                    {{ $item['department']['name'] ?? 'No department' }}
                                @endif
                            </span>
                        </span>
                        @if ($item['status'] !== 'active')
                            <x-portal.chip tone="muted">Inactive</x-portal.chip>
                        @endif
                        @if ($canManage)
                            <span class="flex gap-1">
                                <button type="button" wire:click="edit(@js($item['public_id']))" class="inline-flex min-h-11 items-center rounded-md px-3 text-sm text-accent-ink hover:bg-hover">Edit<span class="sr-only"> {{ $item[$nameField] }}</span></button>
                                <x-portal.confirm :title="'Remove '.$item[$nameField].'?'" body="Anything still in use can't be removed; set it to Inactive instead." confirm="Remove"
                                                  :action="'remove(\''.$item['public_id'].'\')'" :danger="true"
                                                  class="inline-flex min-h-11 items-center rounded-md px-3 text-sm text-danger hover:bg-hover">Remove<span class="sr-only"> {{ $item[$nameField] }}</span></x-portal.confirm>
                            </span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif

        <x-portal.toast :message="$savedMessage" :nonce="$toastNonce" />
    @endif
</div>
