@php use App\Support\Portal\Format; @endphp
<div>
    <x-portal.back :href="route('portal.clients')">Clients</x-portal.back>

    @if ($errorMessage)
        <h1 class="sr-only">Client</h1>
        <x-portal.error :message="$errorMessage" />
    @elseif ($client)
        <h1 class="text-2xl font-semibold">{{ $client['name'] }}</h1>
        @if ($client['client_code'] ?? null)
            <p class="text-muted">{{ $client['client_code'] }}</p>
        @endif
        @if (($client['status'] ?? null) === 'inactive')
            <p class="mt-2"><x-portal.chip tone="muted">Inactive</x-portal.chip></p>
        @endif

        @php($address = implode(', ', Format::addressLines($client)))
        <dl class="mt-6 rounded-xl border border-line bg-raised px-5">
            <x-portal.info-row label="Email" :value="$client['email'] ?? null" copy-label="Copy email" />
            <x-portal.info-row label="Phone" :value="$client['phone'] ?? null" copy-label="Copy phone" />
            <x-portal.info-row label="Website" :value="$client['website'] ?? null" copy-label="Copy website" />
            <x-portal.info-row label="Address" :value="$address === '' ? null : $address" copy-label="Copy address" />
        </dl>

        <section aria-labelledby="client-contacts" class="mt-6">
            <h2 id="client-contacts" class="mb-2 font-semibold">Contacts ({{ $contactsTotal }})</h2>
            @if (count($contacts) === 0)
                <p class="rounded-lg border border-line bg-raised p-4">No active contacts.</p>
            @else
                <ul class="space-y-3">
                    @foreach ($contacts as $contact)
                        <li wire:key="contact-{{ $contact['public_id'] }}" class="rounded-xl border border-line bg-raised px-5">
                            <p class="flex flex-wrap items-center gap-2 pt-4 font-medium">
                                {{ $contact['full_name'] }}
                                @if ($contact['is_primary'] ?? false)
                                    <x-portal.chip tone="info">Primary</x-portal.chip>
                                @endif
                            </p>
                            @if ($contact['job_title'] ?? null)
                                <p class="text-sm text-muted">{{ $contact['job_title'] }}</p>
                            @endif
                            <dl>
                                <x-portal.info-row label="Email" :value="$contact['email'] ?? null" copy-label="Copy email" />
                                <x-portal.info-row label="Phone" :value="$contact['phone'] ?? null" copy-label="Copy phone" />
                            </dl>
                        </li>
                    @endforeach
                </ul>
                @if ($contactsTotal > count($contacts))
                    <p class="mt-2 text-sm text-muted">Showing {{ count($contacts) }} of {{ $contactsTotal }}</p>
                @endif
            @endif
        </section>
    @endif
</div>
