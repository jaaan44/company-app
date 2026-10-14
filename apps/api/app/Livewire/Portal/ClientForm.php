<?php

namespace App\Livewire\Portal;

use App\Livewire\Portal\Concerns\CallsInternalApi;
use App\Livewire\Portal\Concerns\WritesThroughApi;
use App\Services\Portal\PortalApiException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * New client / Edit client (Phase 35D): every field of POST and PATCH
 * /api/v1/clients, for holders of `clients.create` / `clients.update`.
 */
class ClientForm extends Component
{
    use CallsInternalApi;
    use WritesThroughApi;

    /** API field => [property, label, max length]. */
    public const FIELDS = [
        'name' => ['name', 'Name', 255],
        'client_code' => ['code', 'Client code', 50],
        'email' => ['email', 'Email', 255],
        'phone' => ['phone', 'Phone', 50],
        'website' => ['website', 'Website', 255],
        'address_line1' => ['addressLine1', 'Address', 255],
        'address_line2' => ['addressLine2', 'Address line 2', 255],
        'city' => ['city', 'City', 120],
        'state_province' => ['stateProvince', 'State or province', 120],
        'postal_code' => ['postalCode', 'Postal code', 20],
        'country' => ['country', 'Country', 120],
        'notes' => ['notes', 'Notes', 2000],
    ];

    #[Locked]
    public ?string $publicId = null;

    #[Locked]
    public bool $notFound = false;

    public string $name = '';

    public string $code = '';

    public string $status = 'active';

    public string $email = '';

    public string $phone = '';

    public string $website = '';

    public string $addressLine1 = '';

    public string $addressLine2 = '';

    public string $city = '';

    public string $stateProvince = '';

    public string $postalCode = '';

    public string $country = '';

    public string $notes = '';

    public function mount(?string $publicId = null): void
    {
        if ($publicId === null) {
            return;
        }

        $this->publicId = $publicId;
        try {
            $c = $this->api()->get('/clients/'.rawurlencode($publicId))['data'];
            foreach (self::FIELDS as $field => [$property]) {
                $this->{$property} = (string) ($c[$field] ?? '');
            }
            $this->status = (string) $c['status'];
        } catch (PortalApiException $e) {
            if ($e->isNotFound()) {
                $this->notFound = true;
            } else {
                $this->failed($e, 'Something went wrong loading the client.', "You don't have access to clients.");
            }
        }
    }

    public function save(): void
    {
        $this->generalError = null;
        $this->resetErrorBag();

        $body = ['status' => $this->status === 'inactive' ? 'inactive' : 'active'];
        foreach (self::FIELDS as $field => [$property, , $max]) {
            $value = trim((string) $this->{$property});
            if (mb_strlen($value) > $max) {
                $this->addError($property, "Use at most {$max} characters.");
            }
            $body[$field] = $value === '' ? null : $value;
        }
        if ($body['name'] === null) {
            $this->addError('name', 'Give the client a name.');
        }
        if ($body['email'] !== null && filter_var($body['email'], FILTER_VALIDATE_EMAIL) === false) {
            $this->addError('email', 'Enter a valid email address.');
        }
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        try {
            if ($this->publicId === null) {
                $c = $this->api()->send('POST', '/clients', json: $body)['data'];
                session()->flash('status', 'Client added.');
            } else {
                $c = $this->api()->send('PATCH', '/clients/'.rawurlencode($this->publicId), json: $body)['data'];
                session()->flash('status', 'Changes saved.');
            }
            $this->redirect(route('portal.client', $c['public_id']), navigate: true);
        } catch (PortalApiException $e) {
            $map = ['status' => 'status'];
            foreach (self::FIELDS as $field => [$property]) {
                $map[$field] = $property;
            }
            $this->applyWriteFailure($e, $map, "Couldn't save the client. Please try again.");
        }
    }

    public function render(): View
    {
        return view('livewire.portal.client-form');
    }
}
