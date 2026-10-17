@php use App\Livewire\Portal\ClientForm; @endphp
<div>
    @if ($publicId === null)
        <x-portal.back :href="route('portal.clients')">Clients</x-portal.back>
    @else
        <x-portal.back :href="route('portal.client', $publicId)">Client</x-portal.back>
    @endif
    <h1 class="sr-only">{{ $publicId === null ? 'New client' : 'Edit client' }}</h1>

    @if ($notFound)
        <x-portal.notice>This client no longer exists.</x-portal.notice>
    @elseif ($errorMessage)
        <x-portal.error :message="$errorMessage" />
    @else
        <form wire:submit="save" class="space-y-5 rounded-xl border border-line bg-raised p-5" novalidate>
            @if ($generalError)
                <p role="alert" class="font-medium text-danger">{{ $generalError }}</p>
            @endif
            <div class="grid gap-5 sm:grid-cols-2">
                @foreach (ClientForm::FIELDS as $field => [$property, $label, $max])
                    @if ($field === 'notes')
                        @continue
                    @endif
                    @include('livewire.portal.partials.field', [
                        'id' => 'client-'.$property, 'label' => $label, 'model' => $property, 'error' => $property, 'max' => $max,
                        'type' => match ($field) { 'email' => 'email', 'phone' => 'tel', 'website' => 'url', default => 'text' },
                        'optional' => $field !== 'name',
                    ])
                @endforeach
                <div>
                    <label for="client-status" class="mb-1 block text-sm font-semibold">Status</label>
                    <select id="client-status" wire:model="status" class="field w-full">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
            </div>
            @include('livewire.portal.partials.field', ['id' => 'client-notes', 'label' => 'Notes', 'model' => 'notes', 'error' => 'notes', 'max' => 2000, 'textarea' => true, 'rows' => 3, 'optional' => true])

            <div class="flex flex-wrap gap-2">
                <button type="submit" wire:loading.attr="disabled" wire:target="save"
                        class="btn btn-primary">
                    {{ $publicId === null ? 'Add client' : 'Save changes' }}
                </button>
                <a href="{{ $publicId === null ? route('portal.clients') : route('portal.client', $publicId) }}" class="btn btn-text">Cancel</a>
            </div>
        </form>
    @endif
</div>
