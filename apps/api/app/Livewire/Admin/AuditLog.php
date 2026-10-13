<?php

namespace App\Livewire\Admin;

use App\Livewire\Portal\Concerns\CallsInternalApi;
use App\Services\Portal\PortalApiException;
use App\Support\Audit\AuditActions;
use App\Support\CompanyTimezone;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use ReflectionClass;

/**
 * Audit log (Phase 35C, B-5): GET /api/v1/audit-logs (Phase 21), newest
 * first, 25 per page, filterable by action. Read-only; the API allows it
 * to Administrators only.
 */
class AuditLog extends Component
{
    use CallsInternalApi;

    private const PAGE_SIZE = 25;

    #[Url]
    public string $action = '';

    public int $page = 1;

    public function updatedAction(): void
    {
        $this->page = 1;
    }

    public function goToPage(int $page): void
    {
        $this->page = max(1, $page);
    }

    public function render(): View
    {
        $actions = array_values((new ReflectionClass(AuditActions::class))->getConstants());
        sort($actions);
        $result = null;
        try {
            $result = $this->api()->get('/audit-logs', array_filter([
                'action' => in_array($this->action, $actions, true) ? $this->action : null,
                'per_page' => self::PAGE_SIZE,
                'page' => $this->page,
            ]));
        } catch (PortalApiException $e) {
            $this->failed($e, 'Something went wrong loading the audit log.', 'Only Administrators can see the audit log.');
        }

        return view('livewire.admin.audit-log', [
            'entries' => $result['data'] ?? [],
            'meta' => $result['meta'] ?? null,
            'actions' => $actions,
            'utcOffset' => now(CompanyTimezone::value())->format('P'),
        ]);
    }
}
