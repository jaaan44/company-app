<?php

namespace App\Livewire\Portal;

use App\Livewire\Portal\Concerns\CallsInternalApi;
use App\Services\Portal\PortalApiException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Portal Reports (Phase 34E): the mobile Reports screen (Phase 33, R-1)
 * — **Service reports** (`GET /service-reports`) and **Incidents**
 * (`GET /incident-reports`): the reports I can see, newest first, 20 per
 * page, with status chips, and a New button for each.
 */
class Reports extends Component
{
    use CallsInternalApi;

    public const PAGE_SIZE = 20;

    #[Url(except: 'service')]
    public string $type = 'service';

    #[Url(except: 1)]
    public int $page = 1;

    public function show(string $type): void
    {
        $this->type = $type === 'incidents' ? 'incidents' : 'service';
        $this->page = 1;
    }

    public function goToPage(int $page): void
    {
        $this->page = max(1, $page);
    }

    public function render(): View
    {
        $this->errorMessage = null;
        $this->noProfile = false;
        $type = $this->type === 'incidents' ? 'incidents' : 'service';
        $result = null;

        try {
            $result = $this->api()->get($type === 'incidents' ? '/incident-reports' : '/service-reports', [
                'per_page' => self::PAGE_SIZE,
                'page' => max(1, $this->page),
            ]);
        } catch (PortalApiException $e) {
            $this->failed($e, 'Something went wrong loading reports.');
        }

        return view('livewire.portal.reports', [
            'currentType' => $type,
            'reports' => $result['data'] ?? [],
            'meta' => $result['meta'] ?? null,
            'companyDay' => $result['meta']['company_day'] ?? null,
        ]);
    }
}
