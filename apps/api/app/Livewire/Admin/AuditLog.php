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
 *
 * Phase 36D: also by dates (company days since Phase 37), by person (an
 * entry's actor) and by record (its id); clicking a person or a record id
 * shows only their entries.
 */
class AuditLog extends Component
{
    use CallsInternalApi;

    private const PAGE_SIZE = 25;

    #[Url]
    public string $action = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    #[Url(except: '')]
    public string $actor = '';

    #[Url(except: '')]
    public string $actorName = '';

    #[Url(except: '')]
    public string $record = '';

    public int $page = 1;

    public function updated(string $property): void
    {
        if ($property !== 'page') {
            $this->page = 1;
        }
    }

    public function filterActor(string $publicId, string $name): void
    {
        $this->actor = preg_match('/^[0-9A-Za-z]{1,64}$/', $publicId) ? $publicId : '';
        $this->actorName = $this->actor === '' ? '' : $name;
        $this->page = 1;
    }

    public function filterRecord(string $publicId): void
    {
        $this->record = preg_match('/^[0-9A-Za-z]{1,64}$/', $publicId) ? $publicId : '';
        $this->page = 1;
    }

    public function clearFilters(): void
    {
        $this->reset('action', 'from', 'to', 'actor', 'actorName', 'record');
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
                'from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->from) ? $this->from : null,
                'to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->to) ? $this->to : null,
                'actor' => preg_match('/^[0-9A-Za-z]{1,64}$/', $this->actor) ? $this->actor : null,
                'entity_public_id' => preg_match('/^[0-9A-Za-z]{1,64}$/', $this->record) ? $this->record : null,
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
