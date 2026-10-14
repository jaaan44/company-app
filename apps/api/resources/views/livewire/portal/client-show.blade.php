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

        @if ($mayEdit || $mayDelete)
            <div class="mt-3 flex flex-wrap gap-2">
                @if ($mayEdit)
                    <a href="{{ route('portal.clients.edit', $client['public_id']) }}" class="inline-flex min-h-11 items-center rounded-full border border-line px-4 font-medium hover:bg-hover">Edit client</a>
                    <a href="{{ route('portal.contacts.new', $client['public_id']) }}" class="inline-flex min-h-11 items-center gap-2 rounded-full border border-line px-4 font-medium hover:bg-hover"><x-portal.icon name="add" class="size-5" /> Add contact</a>
                @endif
                @if ($mayDelete)
                    <x-portal.confirm title="Delete this client?" body="A client with projects, service reports or incidents can't be deleted. Set it to Inactive instead." confirm="Delete" action="delete()" :danger="true"
                                      class="inline-flex min-h-11 items-center rounded-full border border-line px-4 font-medium text-danger hover:bg-hover">Delete client</x-portal.confirm>
                @endif
            </div>
        @endif
        @if ($generalError)
            <p role="alert" class="mt-3 font-medium text-danger">{{ $generalError }}</p>
        @endif
        <x-portal.toast :message="$savedMessage" :nonce="$toastNonce" />

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
                <p class="rounded-lg border border-line bg-raised p-4">{{ $mayEdit ? 'No contacts yet.' : 'No active contacts.' }}</p>
            @else
                <ul class="space-y-3">
                    @foreach ($contacts as $contact)
                        <li wire:key="contact-{{ $contact['public_id'] }}" class="rounded-xl border border-line bg-raised px-5">
                            <p class="flex flex-wrap items-center gap-2 pt-4 font-medium">
                                {{ $contact['full_name'] }}
                                @if ($contact['is_primary'] ?? false)
                                    <x-portal.chip tone="info">Primary</x-portal.chip>
                                @endif
                                @if (($contact['status'] ?? 'active') === 'inactive')
                                    <x-portal.chip tone="muted">Inactive</x-portal.chip>
                                @endif
                            </p>
                            @if ($contact['job_title'] ?? null)
                                <p class="text-sm text-muted">{{ $contact['job_title'] }}</p>
                            @endif
                            <dl>
                                <x-portal.info-row label="Email" :value="$contact['email'] ?? null" copy-label="Copy email" />
                                <x-portal.info-row label="Phone" :value="$contact['phone'] ?? null" copy-label="Copy phone" />
                            </dl>
                            @if ($mayEdit)
                                <div class="flex flex-wrap gap-1 py-2">
                                    <a href="{{ route('portal.contacts.edit', [$client['public_id'], $contact['public_id']]) }}" class="inline-flex min-h-11 items-center rounded-md px-3 text-sm text-accent-ink hover:bg-hover">Edit<span class="sr-only"> {{ $contact['full_name'] }}</span></a>
                                    <x-portal.confirm :title="'Remove '.$contact['full_name'].'?'" body="This can't be undone. To keep the history, set the contact to Inactive instead." confirm="Remove"
                                                      :action="'removeContact(\''.$contact['public_id'].'\')'" :danger="true"
                                                      class="inline-flex min-h-11 items-center rounded-md px-3 text-sm text-danger hover:bg-hover">Remove<span class="sr-only"> {{ $contact['full_name'] }}</span></x-portal.confirm>
                                </div>
                            @endif
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
