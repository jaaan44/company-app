<?php

namespace App\Livewire\Portal;

use App\Livewire\Portal\Concerns\CallsInternalApi;
use App\Livewire\Portal\Concerns\WritesThroughApi;
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
    use WritesThroughApi;

    public const CONTACTS_PAGE_SIZE = 50;

    #[Locked]
    public string $publicId = '';

    public function mount(string $publicId): void
    {
        $this->publicId = $publicId;
    }

    public ?string $savedMessage = null;

    public int $toastNonce = 0;

    /** Phase 35D (D-5): Delete, after the in-app confirmation. */
    public function delete(): void
    {
        $this->generalError = null;
        try {
            $this->api()->send('DELETE', '/clients/'.rawurlencode($this->publicId));
            session()->flash('status', 'Client deleted.');
            $this->redirect(route('portal.clients'), navigate: true);
        } catch (PortalApiException $e) {
            $this->applyWriteFailure($e, [], "Couldn't delete the client. Please try again.");
        }
    }

    public function removeContact(string $contactId): void
    {
        $this->generalError = null;
        if (! preg_match('/^[0-9A-Za-z]{1,64}$/', $contactId)) {
            return;
        }
        try {
            $this->api()->send('DELETE', '/contacts/'.$contactId);
            $this->savedMessage = 'Contact removed.';
            $this->toastNonce++;
        } catch (PortalApiException $e) {
            $this->applyWriteFailure($e, [], "Couldn't remove the contact. Please try again.");
        }
    }

    public function render(): View
    {
        $this->errorMessage = null;
        $client = null;
        $contacts = null;

        try {
            $client = $this->api()->get('/clients/'.rawurlencode($this->publicId))['data'] ?? null;
            // Those who may edit contacts see inactive ones too, so they can change them back.
            $contacts = $this->api()->get('/contacts', array_filter(['client' => $this->publicId, 'status' => $this->may('clients.update') ? null : 'active', 'per_page' => self::CONTACTS_PAGE_SIZE, 'page' => 1]));
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
            'mayEdit' => $client !== null && $this->may('clients.update'),
            'mayDelete' => $client !== null && $this->may('clients.delete'),
        ]);
    }
}
