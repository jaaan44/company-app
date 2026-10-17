<?php

namespace App\Livewire\Admin;

use App\Livewire\Portal\Concerns\CallsInternalApi;
use App\Services\Portal\PortalApiException;
use App\Support\Admin\Reports;
use App\Support\CompanyTimezone;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Reports → one report (Phase 36D): its filters, the rows the API returns
 * (25 per page) and **Download CSV** with the same filters
 * (`admin.reports.export`, which streams the API's own export).
 */
class ReportView extends Component
{
    use CallsInternalApi;

    #[Locked]
    public string $report;

    /** @var array<string, string> */
    #[Url(as: 'f')]
    public array $filters = [];

    /** Phase 37B: the API's `sort` key, "-" first for descending; empty for the report's own order. */
    #[Url(as: 'sort', except: '')]
    public string $sort = '';

    public int $page = 1;

    public function mount(string $report): void
    {
        $this->report = $report;
        $this->filters = Reports::query($report, $this->filters);
    }

    public function updatedFilters(): void
    {
        $this->page = 1;
    }

    /** Phase 37B (DEC-068): sort by a column; again to reverse it. */
    public function sortBy(string $key): void
    {
        if (! in_array($key, Reports::all()[$this->report]['sorts'], true)) {
            return;
        }
        $this->sort = $this->sort === $key ? '-'.$key : $key;
        $this->page = 1;
    }

    /** Phase 37B (DEC-068): back to the list's starting filters. */
    public function clearFilters(): void
    {
        $this->filters = [];
        $this->page = 1;
    }

    public function goToPage(int $page): void
    {
        $this->page = max(1, $page);
    }

    public function render(): View
    {
        $definition = Reports::all()[$this->report];
        $query = Reports::query($this->report, $this->filters + ['sort' => $this->sort]);
        $result = null;
        try {
            $result = $this->api()->get($definition['path'], $query + ['per_page' => 25, 'page' => $this->page]);
        } catch (PortalApiException $e) {
            $this->failed($e, 'Something went wrong loading the report.', "You don't have access to this report.");
        }

        return view('livewire.admin.report-view', [
            'definition' => $definition,
            'rows' => $result['data'] ?? [],
            'meta' => $result['meta'] ?? null,
            'exportUrl' => route('admin.reports.export', ['report' => $this->report] + $query),
            'timezone' => CompanyTimezone::value(),
        ]);
    }
}
