<?php

namespace App\Livewire\Admin;

use App\Livewire\Portal\Concerns\CallsInternalApi;
use App\Livewire\Portal\Concerns\WritesThroughApi;
use App\Services\Portal\PortalApiException;
use App\Support\CompanyTimezone;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Work → Schedule (Phase 36C): the schedule entries the API lets this
 * person see (an Administrator sees all), from a date on, filtered by
 * activity type, 25 per page. Each opens its edit page; New schedule
 * entry opens the form.
 */
class ScheduleEntries extends Component
{
    use CallsInternalApi;
    use WritesThroughApi;

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $type = '';

    public int $page = 1;

    public function mount(): void
    {
        if ($this->from === '') {
            $this->from = now(CompanyTimezone::value())->toDateString();
        }
    }

    public function updated(string $property): void
    {
        if ($property !== 'page') {
            $this->page = 1;
        }
    }

    public function goToPage(int $page): void
    {
        $this->page = max(1, $page);
    }

    public function render(): View
    {
        $result = null;
        try {
            $result = $this->api()->get('/schedule-entries', [
                'from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->from) ? $this->from : null,
                'activity_type' => array_key_exists($this->type, ScheduleEntryForm::TYPES) ? $this->type : null,
                'per_page' => 25,
                'page' => $this->page,
            ]);
        } catch (PortalApiException $e) {
            $this->failed($e, 'Something went wrong loading the schedule.', "You don't have access to the schedule.");
        }

        return view('livewire.admin.schedule-entries', [
            'entries' => $result['data'] ?? [],
            'meta' => $result['meta'] ?? null,
            'timezone' => CompanyTimezone::value(),
        ]);
    }
}
