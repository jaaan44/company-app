<?php

namespace App\Livewire\Portal;

use App\Livewire\Portal\Concerns\CallsInternalApi;
use App\Services\Portal\PortalApiException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Portal client page (Phase 34B): the mobile client detail (Phase 29C) —
 * `GET /clients/{id}` and its first 50 active contacts.
 */
class ClientShow extends Component
{
    use CallsInternalApi;

    public const CONTACTS_PAGE_SIZE = 50;

    #[Locked]
    public string $publicId = '';

    public function mount(string $publicId): void
    {
        $this->publicId = $publicId;
    }

    public function render(): View
    {
        $this->errorMessage = null;
        $client = null;
        $contacts = null;

        try {
            $client = $this->api()->get('/clients/'.rawurlencode($this->publicId))['data'] ?? null;
            $contacts = $this->api()->get('/contacts', ['client' => $this->publicId, 'status' => 'active', 'per_page' => self::CONTACTS_PAGE_SIZE, 'page' => 1]);
        } catch (PortalApiException $e) {
            $client = null;
            if ($e->isNotFound()) {
                $this->errorMessage = 'This client no longer exists.';
            } else {
                $this->failed($e, 'Something went wrong loading this client.', forbidden: "You don't have access to clients.");
            }
        }

        return view('livewire.portal.client-show', [
            'client' => $client,
            'contacts' => $contacts['data'] ?? [],
            'contactsTotal' => $contacts['meta']['total'] ?? 0,
        ]);
    }
}
