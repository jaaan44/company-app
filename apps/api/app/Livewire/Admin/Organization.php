<?php

namespace App\Livewire\Admin;

use App\Livewire\Portal\Concerns\CallsInternalApi;
use App\Livewire\Portal\Concerns\WritesThroughApi;
use App\Services\Portal\PortalApiException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * People → Departments, teams and positions (Phase 36A): one tab each,
 * through /api/v1/departments, /teams and /positions. Add, edit and
 * remove show with `organization.manage`; the API refuses removing
 * anything still in use and says why.
 */
class Organization extends Component
{
    use CallsInternalApi;
    use WritesThroughApi;

    /** Tab => [singular, the API's name field]. */
    public const TABS = [
        'departments' => ['department', 'name'],
        'teams' => ['team', 'name'],
        'positions' => ['position', 'title'],
    ];

    #[Url(as: 'tab')]
    public string $tab = 'departments';

    /** null: no form open; '': adding; otherwise the public id being edited. */
    #[Locked]
    public ?string $editing = null;

    public string $name = '';

    public string $departmentId = '';

    public string $description = '';

    public string $status = 'active';

    public string $sortOrder = '0';

    public ?string $savedMessage = null;

    public int $toastNonce = 0;

    public function updatedTab(): void
    {
        $this->cancel();
    }

    private function tab(): string
    {
        return array_key_exists($this->tab, self::TABS) ? $this->tab : 'departments';
    }

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
            $item = $this->api()->get('/'.$this->tab().'/'.$publicId)['data'];
        } catch (PortalApiException $e) {
            $this->applyWriteFailure($e, [], 'Something went wrong. Please try again.');

            return;
        }
        $this->editing = $publicId;
        $this->name = (string) $item[self::TABS[$this->tab()][1]];
        $this->departmentId = (string) ($item['department']['public_id'] ?? '');
        $this->description = (string) ($item['description'] ?? '');
        $this->status = (string) $item['status'];
        $this->sortOrder = (string) $item['sort_order'];
    }

    public function cancel(): void
    {
        $this->editing = null;
        $this->generalError = null;
        $this->resetErrorBag();
        $this->reset('name', 'departmentId', 'description', 'status', 'sortOrder');
    }

    public function save(): void
    {
        if ($this->editing === null) {
            return;
        }
        $this->generalError = null;
        $this->resetErrorBag();
        [$singular, $nameField] = self::TABS[$this->tab()];

        $name = trim($this->name);
        if ($name === '') {
            $this->addError('name', 'Give the '.$singular.' a '.($nameField === 'title' ? 'title' : 'name').'.');
        } elseif (mb_strlen($name) > 255) {
            $this->addError('name', 'Use at most 255 characters.');
        }
        if (! ctype_digit($this->sortOrder)) {
            $this->addError('sortOrder', 'Use a whole number, 0 or more.');
        }
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        $body = [
            $nameField => $name,
            'description' => trim($this->description) === '' ? null : trim($this->description),
            'status' => $this->status === 'inactive' ? 'inactive' : 'active',
            'sort_order' => (int) $this->sortOrder,
        ];
        if ($this->tab() !== 'departments') {
            $body['department_id'] = $this->departmentId === '' ? null : $this->departmentId;
        }

        try {
            if ($this->editing === '') {
                $this->api()->send('POST', '/'.$this->tab(), json: $body);
                $this->toast(ucfirst($singular).' added.');
            } else {
                $this->api()->send('PATCH', '/'.$this->tab().'/'.$this->editing, json: $body);
                $this->toast('Changes saved.');
            }
            $this->cancel();
        } catch (PortalApiException $e) {
            $this->applyWriteFailure($e, [
                $nameField => 'name', 'department_id' => 'departmentId', 'description' => 'description', 'status' => 'status', 'sort_order' => 'sortOrder',
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
            $this->api()->send('DELETE', '/'.$this->tab().'/'.$publicId);
            $this->toast(ucfirst(self::TABS[$this->tab()][0]).' removed.');
        } catch (PortalApiException $e) {
            $this->applyWriteFailure($e, [], "Couldn't remove it. Please try again.");
            if ($e->status === 409) {
                $this->generalError .= ' To keep it out of use, set it to Inactive instead.';
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
        $items = [];
        $departments = [];
        try {
            $items = $this->api()->get('/'.$this->tab(), ['per_page' => 200])['data'] ?? [];
            if ($this->tab() !== 'departments' && $this->editing !== null) {
                $departments = $this->api()->get('/departments', ['per_page' => 200])['data'] ?? [];
            }
        } catch (PortalApiException $e) {
            $this->failed($e, 'Something went wrong loading the company structure.', "You don't have access to the company structure.");
        }

        return view('livewire.admin.organization', [
            'current' => $this->tab(),
            'items' => $items,
            'departments' => $departments,
            'canManage' => $this->may('organization.manage'),
        ]);
    }
}
