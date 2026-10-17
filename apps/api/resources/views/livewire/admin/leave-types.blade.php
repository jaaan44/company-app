<div>
    <x-portal.back :href="route('admin.section', 'hr')">HR</x-portal.back>
    <h1 class="sr-only">Leave types</h1>

    @if ($errorMessage)
        <x-portal.error :message="$errorMessage" />
    @else
        @if ($generalError && $editing === null)
            <p role="alert" class="mb-4 font-medium text-danger">{{ $generalError }}</p>
        @endif
        @if ($canManage && $editing === null)
            <button type="button" wire:click="add" class="btn btn-primary mb-4">
                <x-portal.icon name="add" class="size-5" /> New leave type
            </button>
        @endif

        @if ($editing !== null)
            <form wire:submit="save" class="mb-6 space-y-5 rounded-xl border border-line bg-raised p-5" novalidate>
                <h2 class="font-semibold">{{ $editing === '' ? 'New leave type' : 'Edit leave type' }}</h2>
                @if ($generalError)
                    <p role="alert" class="font-medium text-danger">{{ $generalError }}</p>
                @endif
                <div class="grid gap-5 sm:grid-cols-2">
                    @include('livewire.portal.partials.field', ['id' => 'lt-name', 'label' => 'Name', 'model' => 'name', 'error' => 'name', 'max' => 255])
                    @include('livewire.portal.partials.field', ['id' => 'lt-code', 'label' => 'Code', 'model' => 'code', 'error' => 'code', 'max' => 50, 'optional' => true])
                    <div>
                        <label for="lt-status" class="mb-1 block text-sm font-semibold">Status</label>
                        <select id="lt-status" wire:model="status" class="field w-full">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                    @include('livewire.portal.partials.field', ['id' => 'lt-sort', 'label' => 'Order in lists', 'model' => 'sortOrder', 'error' => 'sortOrder', 'type' => 'number'])
                </div>
                <label class="flex min-h-11 items-center gap-3">
                    <input type="checkbox" wire:model="isPaid" class="size-5 accent-[var(--color-accent)]">
                    <span><span class="font-semibold">Paid</span> <span class="text-sm text-muted">— paid leave has a yearly allowance per person</span></span>
                </label>
                @include('livewire.portal.partials.field', ['id' => 'lt-description', 'label' => 'Description', 'model' => 'description', 'error' => 'description', 'textarea' => true, 'rows' => 2, 'max' => 1000, 'optional' => true])
                <div class="flex flex-wrap gap-2">
                    <button type="submit" class="btn btn-primary">{{ $editing === '' ? 'Add leave type' : 'Save changes' }}</button>
                    <button type="button" wire:click="cancel" class="btn btn-text">Cancel</button>
                </div>
            </form>
        @endif

        @if ($types === [])
            <x-portal.empty>No leave types yet.</x-portal.empty>
        @else
            <ul class="divide-y divide-line overflow-hidden rounded-xl border border-line bg-raised">
                @foreach ($types as $type)
                    <li wire:key="lt-{{ $type['public_id'] }}" class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 px-5 py-3">
                        <span class="min-w-0 flex-1 basis-56">
                            <span class="block font-medium">{{ $type['name'] }}@if ($type['code']) <span class="text-sm font-normal text-muted">({{ $type['code'] }})</span>@endif</span>
                            <span class="block text-sm text-muted">{{ $type['is_paid'] ? 'Paid — yearly allowance' : 'Unpaid — no balance' }}</span>
                        </span>
                        @if ($type['status'] !== 'active')
                            <x-portal.chip tone="muted">Inactive</x-portal.chip>
                        @endif
                        @if ($canManage)
                            <span class="flex gap-1">
                                <button type="button" wire:click="edit(@js($type['public_id']))" class="btn btn-text btn-compact text-sm">Edit<span class="sr-only"> {{ $type['name'] }}</span></button>
                                <x-portal.confirm :title="'Remove '.$type['name'].'?'" body="A type with requests or balances can't be removed; set it to Inactive instead." confirm="Remove"
                                                  :action="'remove(\''.$type['public_id'].'\')'" :danger="true"
                                                  class="btn btn-danger-text btn-compact text-sm">Remove<span class="sr-only"> {{ $type['name'] }}</span></x-portal.confirm>
                            </span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif

        <x-portal.toast :message="$savedMessage" :nonce="$toastNonce" />
    @endif
</div>
