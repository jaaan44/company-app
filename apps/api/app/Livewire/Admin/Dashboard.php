<?php

namespace App\Livewire\Admin;

use App\Livewire\Portal\Concerns\CallsInternalApi;
use App\Services\Portal\PortalApiException;
use App\Support\Admin\Sections;
use App\Support\CompanyTimezone;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Admin Backoffice Dashboard (Phase 35B, B-3): the figures of
 * GET /api/v1/dashboard (Phase 20), read-only, for this month or a chosen
 * period. Like every portal page it calls the API in-process and adds no
 * rule of its own.
 */
class Dashboard extends Component
{
    use CallsInternalApi;

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public ?string $periodError = null;

    public function thisMonth(): void
    {
        $this->from = '';
        $this->to = '';
    }

    public function render(): View
    {
        $this->errorMessage = null;
        $this->periodError = null;
        $dashboard = null;

        try {
            $dashboard = $this->api()->get('/dashboard', array_filter(['from' => $this->from, 'to' => $this->to]))['data'] ?? null;
        } catch (PortalApiException $e) {
            if ($e->status === 422) {
                $this->periodError = $e->firstErrorFor('to') ?? $e->firstErrorFor('from') ?? 'Choose a valid period.';
            } else {
                $this->failed($e, 'Something went wrong loading the dashboard.', 'You don\'t have access to the dashboard.');
            }
        }

        return view('livewire.admin.dashboard', [
            'd' => $dashboard,
            'utcOffset' => now(CompanyTimezone::value())->format('P'),
            'sections' => array_keys(Sections::visibleTo(auth()->user())),
        ]);
    }
}
