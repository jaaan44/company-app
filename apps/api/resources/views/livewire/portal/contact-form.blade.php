@php use App\Livewire\Portal\ContactForm; @endphp
<div>
    <x-portal.back :href="route('portal.client', $clientId)">{{ $clientName !== '' ? $clientName : 'Client' }}</x-portal.back>
    <h1 class="sr-only">{{ $publicId === null ? 'New contact' : 'Edit contact' }}</h1>

    @if ($notFound)
        <x-portal.notice>This contact no longer exists.</x-portal.notice>
    @elseif ($errorMessage)
        <x-portal.error :message="$errorMessage" />
    @else
        <form wire:submit="save" class="space-y-5 rounded-xl border border-line bg-raised p-5" novalidate>
            @if ($generalError)
                <p role="alert" class="font-medium text-danger">{{ $generalError }}</p>
            @endif
            <p class="text-muted">For {{ $clientName }}</p>
            <div class="grid gap-5 sm:grid-cols-2">
                @foreach (ContactForm::FIELDS as $field => [$property, $label, $max])
                    @if ($field === 'notes')
                        @continue
                    @endif
                    @include('livewire.portal.partials.field', [
                        'id' => 'contact-'.$property, 'label' => $label, 'model' => $property, 'error' => $property, 'max' => $max,
                        'type' => match ($field) { 'email' => 'email', 'phone' => 'tel', default => 'text' },
                        'optional' => ! in_array($field, ['first_name', 'last_name'], true),
                    ])
                @endforeach
                <div>
                    <label for="contact-status" class="mb-1 block text-sm font-semibold">Status</label>
                    <select id="contact-status" wire:model="status" class="field w-full">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
                <label class="inline-flex min-h-11 items-center gap-3 self-end">
                    <input type="checkbox" wire:model="isPrimary" class="size-5 accent-[var(--color-accent)]"> Primary contact
                </label>
            </div>
            @include('livewire.portal.partials.field', ['id' => 'contact-notes', 'label' => 'Notes', 'model' => 'notes', 'error' => 'notes', 'max' => 2000, 'textarea' => true, 'rows' => 3, 'optional' => true])

            <div class="flex flex-wrap gap-2">
                <button type="submit" wire:loading.attr="disabled" wire:target="save"
                        class="btn btn-primary">
                    {{ $publicId === null ? 'Add contact' : 'Save changes' }}
                </button>
                <a href="{{ route('portal.client', $clientId) }}" class="btn btn-text">Cancel</a>
            </div>
        </form>
    @endif
</div>
