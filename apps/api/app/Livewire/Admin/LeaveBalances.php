<?php

namespace App\Livewire\Admin;

use App\Livewire\Admin\Concerns\PicksStaff;
use App\Livewire\Portal\Concerns\CallsInternalApi;
use App\Livewire\Portal\Concerns\WritesThroughApi;
use App\Services\Portal\PortalApiException;
use App\Support\CompanyTimezone;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * HR → Leave balances (Phase 36B): one person's allowance per paid leave
 * type for a year, with used, pending and remaining, through GET and
 * POST /api/v1/staff/{id}/leave-balances. Setting an allowance needs
 * `leave-requests.manage`; the API checks the numbers.
 */
class LeaveBalances extends Component
{
    use CallsInternalApi;
    use PicksStaff;
    use WritesThroughApi;

    #[Url(as: 'staff', except: '')]
    public string $staffId = '';

    #[Url(except: 0)]
    public int $year = 0;

    /** @var array<string, string> leave type public id => allocated days */
    public array $allocated = [];

    /** @var array<string, string> leave type public id => notes */
    public array $notes = [];

    public ?string $savedMessage = null;

    public int $toastNonce = 0;

    public function mount(): void
    {
        $this->year = $this->year >= 2000 && $this->year <= 2100 ? $this->year : (int) now(CompanyTimezone::value())->year;
        if ($this->staffId !== '' && preg_match('/^[0-9A-Za-z]{1,64}$/', $this->staffId)) {
            try {
                $this->staffName = (string) $this->api()->get('/staff/'.$this->staffId)['data']['display_name'];
            } catch (PortalApiException) {
                $this->staffId = '';
            }
        } else {
            $this->staffId = '';
        }
        $this->loadAllowances();
    }

    protected function staffChosen(): void
    {
        $this->loadAllowances();
    }

    public function updatedYear(): void
    {
        $this->year = max(2000, min(2100, $this->year));
        $this->loadAllowances();
    }

    private function loadAllowances(): void
    {
        $this->allocated = [];
        $this->notes = [];
        foreach ($this->balances() ?? [] as $row) {
            if ($row['is_paid']) {
                $this->allocated[$row['leave_type']['public_id']] = (string) ($row['allocated_days'] ?? '');
                $this->notes[$row['leave_type']['public_id']] = (string) ($row['notes'] ?? '');
            }
        }
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function balances(): ?array
    {
        if ($this->staffId === '') {
            return null;
        }
        try {
            return $this->api()->get('/staff/'.$this->staffId.'/leave-balances', ['year' => $this->year])['data'] ?? [];
        } catch (PortalApiException $e) {
            $this->failed($e, 'Something went wrong loading the balances.', "You don't have access to this person's leave.");

            return null;
        }
    }

    public function saveAllowance(string $typeId): void
    {
        $this->generalError = null;
        $this->resetErrorBag();
        if ($this->staffId === '' || ! array_key_exists($typeId, $this->allocated)) {
            return;
        }
        $days = trim((string) $this->allocated[$typeId]);
        if (! ctype_digit($days) || (int) $days > 365) {
            $this->addError('allocated.'.$typeId, 'Use a whole number of days, 0 to 365.');

            return;
        }
        try {
            $this->api()->send('POST', '/staff/'.$this->staffId.'/leave-balances', json: [
                'leave_type_id' => $typeId,
                'year' => $this->year,
                'allocated_days' => (int) $days,
                'notes' => trim((string) ($this->notes[$typeId] ?? '')) === '' ? null : trim((string) $this->notes[$typeId]),
            ]);
            $this->savedMessage = 'Allowance saved.';
            $this->toastNonce++;
        } catch (PortalApiException $e) {
            $this->applyWriteFailure($e, ['allocated_days' => 'allocated.'.$typeId, 'notes' => 'notes.'.$typeId], "Couldn't save the allowance. Please try again.");
        }
    }

    public function render(): View
    {
        return view('livewire.admin.leave-balances', [
            'balances' => $this->balances(),
            'people' => $this->staffMatches(),
            'canSet' => $this->may('leave-requests.manage'),
        ]);
    }
}
