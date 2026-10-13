<?php

namespace App\Livewire\Admin;

use App\Livewire\Portal\Concerns\CallsInternalApi;
use App\Services\Portal\PortalApiException;
use App\Support\Authorization\PermissionCatalog;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Roles & permissions (Phase 35C, DEC-065): a checkbox per permission for
 * Manager and Staff. Ticking a write ticks its view; unticking a view
 * clears its writes (P-3). Save shows every change first and sends each
 * changed role to PUT /api/v1/roles/{role}/permissions, which checks and
 * audits it. Administrator is shown as always having everything.
 */
class Roles extends Component
{
    use CallsInternalApi;

    /** @var array<string, array<array-key, string>> role => permissions, as ticked (browser state, so not trusted to be a list) */
    public array $grants = [];

    /** @var array<string, list<string>> role => permissions, as saved */
    public array $saved = [];

    public ?string $savedMessage = null;

    public int $toastNonce = 0;

    /** @var array<string, array<array-key, string>> the grants just before a tick, for normalize() */
    protected array $beforeTick = [];

    public function mount(): void
    {
        try {
            foreach ($this->api()->get('/roles')['data'] ?? [] as $role) {
                if ($role['editable'] ?? false) {
                    $this->saved[$role['name']] = $role['permissions'];
                }
            }
            $this->grants = $this->saved;
        } catch (PortalApiException $e) {
            $this->failed($e, 'Something went wrong loading the roles.', 'Only Administrators can change roles and permissions.');
        }
    }

    public function updatingGrants(): void
    {
        $this->beforeTick = $this->grants;
    }

    public function updatedGrants(): void
    {
        foreach (array_keys($this->saved) as $role) {
            $this->grants[$role] = PermissionCatalog::normalize(
                array_values($this->beforeTick[$role] ?? []),
                array_values(array_map('strval', (array) ($this->grants[$role] ?? []))),
            );
        }
    }

    public function resetToDefaults(): void
    {
        foreach (array_keys($this->saved) as $role) {
            $this->grants[$role] = array_values(array_intersect(PermissionCatalog::grantable(), PermissionCatalog::DEFAULTS[$role] ?? []));
        }
    }

    public function discard(): void
    {
        $this->grants = $this->saved;
    }

    public function save(): void
    {
        $this->errorMessage = null;
        foreach (array_keys($this->saved) as $role) {
            $wanted = array_values($this->grants[$role] ?? []);
            if ($this->sameSet($wanted, $this->saved[$role])) {
                continue;
            }
            try {
                $this->saved[$role] = $this->api()->send('PUT', "/roles/{$role}/permissions", json: ['permissions' => $wanted])['data']['permissions'];
                $this->grants[$role] = $this->saved[$role];
            } catch (PortalApiException $e) {
                if ($e->status === 422) {
                    $this->errorMessage = $e->firstErrorFor('permissions') ?? $e->firstErrorFor('role') ?? $e->getMessage();
                } else {
                    $this->failed($e, 'Something went wrong saving the permissions. Nothing more was changed.', 'Only Administrators can change roles and permissions.');
                }

                return;
            }
        }
        $this->savedMessage = 'Permissions saved.';
        $this->toastNonce++;
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private function sameSet(array $a, array $b): bool
    {
        return array_diff($a, $b) === [] && array_diff($b, $a) === [];
    }

    public function render(): View
    {
        $meta = null;
        $roles = [];
        if ($this->saved !== []) {
            try {
                $response = $this->api()->get('/roles');
                $meta = $response['meta'] ?? null;
                $roles = collect($response['data'] ?? [])->keyBy('name')->map(fn ($r) => $r['label'])->all();
            } catch (PortalApiException $e) {
                $this->failed($e, 'Something went wrong loading the roles.', 'Only Administrators can change roles and permissions.');
            }
        }

        $labels = collect($meta['modules'] ?? [])->flatMap(fn ($m) => collect($m['permissions'])->pluck('label', 'name'))->all();
        $changes = [];
        foreach ($this->saved as $role => $saved) {
            $now = array_values($this->grants[$role] ?? []);
            $added = array_values(array_diff($now, $saved));
            $removed = array_values(array_diff($saved, $now));
            if ($added !== [] || $removed !== []) {
                $changes[] = [
                    'role' => $roles[$role] ?? ucfirst($role),
                    'added' => array_map(fn ($n) => $labels[$n] ?? $n, $added),
                    'removed' => array_map(fn ($n) => $labels[$n] ?? $n, $removed),
                ];
            }
        }

        return view('livewire.admin.roles', ['meta' => $meta, 'roleLabels' => $roles, 'changes' => $changes]);
    }
}
