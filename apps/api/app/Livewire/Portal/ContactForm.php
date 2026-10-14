<?php

namespace App\Livewire\Portal;

use App\Livewire\Portal\Concerns\CallsInternalApi;
use App\Livewire\Portal\Concerns\WritesThroughApi;
use App\Services\Portal\PortalApiException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * New contact / Edit contact for a client (Phase 35D): POST and PATCH
 * /api/v1/contacts, for holders of `clients.update` (contacts are part of
 * editing their client, 35A).
 */
class ContactForm extends Component
{
    use CallsInternalApi;
    use WritesThroughApi;

    /** API field => [property, label, max length]. */
    public const FIELDS = [
        'first_name' => ['firstName', 'First name', 255],
        'last_name' => ['lastName', 'Last name', 255],
        'job_title' => ['jobTitle', 'Job title', 255],
        'email' => ['email', 'Email', 255],
        'phone' => ['phone', 'Phone', 50],
        'notes' => ['notes', 'Notes', 2000],
    ];

    #[Locked]
    public string $clientId = '';

    #[Locked]
    public ?string $publicId = null;

    #[Locked]
    public bool $notFound = false;

    #[Locked]
    public string $clientName = '';

    public string $firstName = '';

    public string $lastName = '';

    public string $jobTitle = '';

    public string $email = '';

    public string $phone = '';

    public string $notes = '';

    public bool $isPrimary = false;

    public string $status = 'active';

    public function mount(string $clientId, ?string $publicId = null): void
    {
        $this->clientId = $clientId;
        $this->publicId = $publicId;
        try {
            $this->clientName = (string) ($this->api()->get('/clients/'.rawurlencode($clientId))['data']['name'] ?? '');
            if ($publicId !== null) {
                $c = $this->api()->get('/contacts/'.rawurlencode($publicId))['data'];
                if (($c['client']['public_id'] ?? $clientId) !== $clientId) {
                    $this->notFound = true;

                    return;
                }
                foreach (self::FIELDS as $field => [$property]) {
                    $this->{$property} = (string) ($c[$field] ?? '');
                }
                $this->isPrimary = (bool) ($c['is_primary'] ?? false);
                $this->status = (string) $c['status'];
            }
        } catch (PortalApiException $e) {
            if ($e->isNotFound()) {
                $this->notFound = true;
            } else {
                $this->failed($e, 'Something went wrong loading the contact.', "You don't have access to clients.");
            }
        }
    }

    public function save(): void
    {
        $this->generalError = null;
        $this->resetErrorBag();

        $body = ['is_primary' => $this->isPrimary, 'status' => $this->status === 'inactive' ? 'inactive' : 'active'];
        foreach (self::FIELDS as $field => [$property, , $max]) {
            $value = trim((string) $this->{$property});
            if (mb_strlen($value) > $max) {
                $this->addError($property, "Use at most {$max} characters.");
            }
            $body[$field] = $value === '' ? null : $value;
        }
        if ($body['first_name'] === null) {
            $this->addError('firstName', 'Enter a first name.');
        }
        if ($body['last_name'] === null) {
            $this->addError('lastName', 'Enter a last name.');
        }
        if ($body['email'] !== null && filter_var($body['email'], FILTER_VALIDATE_EMAIL) === false) {
            $this->addError('email', 'Enter a valid email address.');
        }
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        try {
            if ($this->publicId === null) {
                $this->api()->send('POST', '/contacts', json: ['client_id' => $this->clientId, ...$body]);
                session()->flash('status', 'Contact added.');
            } else {
                $this->api()->send('PATCH', '/contacts/'.rawurlencode($this->publicId), json: $body);
                session()->flash('status', 'Changes saved.');
            }
            $this->redirect(route('portal.client', $this->clientId), navigate: true);
        } catch (PortalApiException $e) {
            $map = ['is_primary' => 'isPrimary', 'status' => 'status'];
            foreach (self::FIELDS as $field => [$property]) {
                $map[$field] = $property;
            }
            $this->applyWriteFailure($e, $map, "Couldn't save the contact. Please try again.");
        }
    }

    public function render(): View
    {
        return view('livewire.portal.contact-form');
    }
}
