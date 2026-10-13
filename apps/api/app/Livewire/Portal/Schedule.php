<?php

namespace App\Livewire\Portal;

use App\Livewire\Portal\Concerns\CallsInternalApi;
use App\Services\Portal\PortalApiException;
use App\Support\Portal\Format;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Portal Schedule (Phase 34B): the mobile Schedule tab (Phase 30A) from
 * `GET /me/schedule` — seven days from the company today, Previous week /
 * Next week / Today, each day's meetings, open tasks due and approved
 * leave, in company time. A week other than the current one keeps its
 * first day in the URL (`?from=`); the current week has none.
 */
class Schedule extends Component
{
    use CallsInternalApi;

    public const WINDOW_DAYS = 7;

    #[Url(except: '')]
    public string $from = '';

    /** The first day of the week on screen (learnt from the API for the current week). */
    #[Locked]
    public string $shownFrom = '';

    public function move(int $days): void
    {
        if ($this->validDate($this->shownFrom)) {
            $this->from = CarbonImmutable::parse($this->shownFrom)->addDays($days)->toDateString();
        }
    }

    public function today(): void
    {
        $this->from = '';
    }

    public function render(): View
    {
        $this->errorMessage = null;
        $this->noProfile = false;
        $result = null;

        try {
            $start = $this->from;
            if (! $this->validDate($start)) {
                // The company today comes from the API, never the server clock.
                $probe = CarbonImmutable::now('UTC')->toDateString();
                $start = $this->api()->get('/me/schedule', ['from' => $probe, 'to' => $probe])['data']['company_day']['date'];
            }
            $this->shownFrom = $start;
            $to = CarbonImmutable::parse($start)->addDays(self::WINDOW_DAYS - 1)->toDateString();
            $result = $this->api()->get('/me/schedule', ['from' => $start, 'to' => $to]);
            if (($result['data']['items'] ?? null) === null) {
                $this->noProfile = true;
            }
        } catch (PortalApiException $e) {
            $this->failed($e, 'Something went wrong loading your schedule.', forbidden: "You don't have access to the schedule.");
        }

        $days = [];
        if ($result !== null && ! $this->noProfile) {
            $offset = $result['data']['company_day']['utc_offset'];
            for ($i = 0; $i < self::WINDOW_DAYS; $i++) {
                $date = CarbonImmutable::parse($this->shownFrom)->addDays($i)->toDateString();
                $days[$date] = array_values(array_filter($result['data']['items'], fn ($item) => Format::overlapsDay($item, $date, $offset)));
            }
        }

        return view('livewire.portal.schedule', [
            'days' => $days,
            'companyDay' => $result['data']['company_day'] ?? null,
        ]);
    }

    private function validDate(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4));
    }
}
