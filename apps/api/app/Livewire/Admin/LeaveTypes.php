<?php

namespace App\Livewire\Admin;

use App\Livewire\Portal\Concerns\CallsInternalApi;
use App\Livewire\Portal\Concerns\WritesThroughApi;
use App\Services\Portal\PortalApiException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * HR → Leave types (Phase 36B), through /api/v1/leave-types: the kinds of
 * leave, paid or unpaid. Add, edit and remove show with
 * `leave-types.manage`; a type with requests or balances can't be
 * removed (the API says so), only set to Inactive.
 */
class LeaveTypes extends Component
{
    use CallsInternalApi;
    use WritesThroughApi;

    /** null: no form; '': adding; otherwise the type being edited. */
    #[Locked]
    public ?string $editing = null;

    public string $name = '';

    public string $code = '';

    public bool $isPaid = true;

    public string $status = 'active';

    public string $sortOrder = '0';

    public string $description = '';

    public ?string $savedMessage = null;

    public int $toastNonce = 0;

    public function add(): void
    {
        $this->cancel();
        $this->editing = '';
    }

    public function edit(string $publicId): void
    {
        $this->cancel();
        if (! preg_match('/^[0-9A-Za-z]{1,64}$/', $publicId)) {
            return;
        }
        try {
            $type = $this->api()->get('/leave-types/'.$publicId)['data'];
        } catch (PortalApiException $e) {
            $this->applyWriteFailure($e, [], 'Something went wrong. Please try again.');

            return;
        }
        $this->editing = $publicId;
        $this->name = (string) $type['name'];
        $this->code = (string) ($type['code'] ?? '');
        $this->isPaid = (bool) $type['is_paid'];
        $this->status = (string) $type['status'];
        $this->sortOrder = (string) $type['sort_order'];
        $this->description = (string) ($type['description'] ?? '');
    }

    public function cancel(): void
    {
        $this->editing = null;
        $this->generalError = null;
        $this->resetErrorBag();
        $this->reset('name', 'code', 'isPaid', 'status', 'sortOrder', 'description');
    }

    public function save(): void
    {
        if ($this->editing === null) {
            return;
        }
        $this->generalError = null;
        $this->resetErrorBag();
        $name = trim($this->name);
        if ($name === '' || mb_strlen($name) > 255) {
            $this->addError('name', 'Give the type a name (at most 255 characters).');
        }
        if (! ctype_digit($this->sortOrder)) {
            $this->addError('sortOrder', 'Use a whole number, 0 or more.');
        }
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        $body = [
            'name' => $name,
            'code' => trim($this->code) === '' ? null : trim($this->code),
            'is_paid' => $this->isPaid,
            'status' => $this->status === 'inactive' ? 'inactive' : 'active',
            'sort_order' => (int) $this->sortOrder,
            'description' => trim($this->description) === '' ? null : trim($this->description),
        ];
        try {
            if ($this->editing === '') {
                $this->api()->send('POST', '/leave-types', json: $body);
                $this->toast('Leave type added.');
            } else {
                $this->api()->send('PATCH', '/leave-types/'.$this->editing, json: $body);
                $this->toast('Changes saved.');
            }
            $this->cancel();
        } catch (PortalApiException $e) {
            $this->applyWriteFailure($e, [
                'name' => 'name', 'code' => 'code', 'is_paid' => 'isPaid', 'status' => 'status', 'sort_order' => 'sortOrder', 'description' => 'description',
            ], "Couldn't save. Please try again.");
        }
    }

    public function remove(string $publicId): void
    {
        $this->generalError = null;
        if (! preg_match('/^[0-9A-Za-z]{1,64}$/', $publicId)) {
            return;
        }
        try {
            $this->api()->send('DELETE', '/leave-types/'.$publicId);
            $this->toast('Leave type removed.');
        } catch (PortalApiException $e) {
            $this->applyWriteFailure($e, [], "Couldn't remove it. Please try again.");
            if ($e->status === 409) {
                $this->generalError .= ' To stop it being used, set it to Inactive instead.';
            }
        }
    }

    private function toast(string $message): void
    {
        $this->savedMessage = $message;
        $this->toastNonce++;
    }

    public function render(): View
    {
        $types = [];
        try {
            $types = $this->api()->get('/leave-types', ['per_page' => 100])['data'] ?? [];
        } catch (PortalApiException $e) {
            $this->failed($e, 'Something went wrong loading the leave types.', "You don't have access to leave types.");
        }

        return view('livewire.admin.leave-types', ['types' => $types, 'canManage' => $this->may('leave-types.manage')]);
    }
}
