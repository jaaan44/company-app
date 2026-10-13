<?php

namespace App\Livewire\Portal;

use App\Livewire\Portal\Concerns\CallsInternalApi;
use App\Services\Portal\PortalApiException;
use App\Support\Portal\Format;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Portal "Request leave" (Phase 34C): the mobile form (Phase 31) —
 * `POST /me/leave-requests`. The types are my balance rows; the form
 * checks what the mobile form checks (dates in order and within one
 * calendar year, a reason of 3–1,000 characters) and the API stays
 * authoritative: its 422 errors land on the same fields and its 409
 * (overlap or not enough balance) shows at the top in its own words.
 */
class LeaveForm extends Component
{
    use CallsInternalApi;

    public const MIN_REASON = 3;

    public const MAX_REASON = 1000;

    #[Locked]
    public ?string $companyDate = null;

    public string $type = '';

    public string $startDate = '';

    public string $endDate = '';

    public string $reason = '';

    public ?string $generalError = null;

    public function mount(): void
    {
        try {
            $this->companyDate = $this->api()->get('/me/leave-requests', ['per_page' => 1])['meta']['company_day']['date'] ?? null;
        } catch (PortalApiException $e) {
            $this->failed($e, 'Something went wrong loading the form.');
        }
    }

    public function updatedStartDate(): void
    {
        if ($this->endDate === '' || ($this->validDate($this->startDate) && $this->endDate < $this->startDate)) {
            $this->endDate = $this->startDate;
        }
    }

    public function submit(): void
    {
        $this->generalError = null;
        if (! $this->validateForm()) {
            return;
        }

        try {
            $this->api()->send('POST', '/me/leave-requests', json: [
                'leave_type_id' => $this->type,
                'start_date' => $this->startDate,
                'end_date' => $this->endDate,
                'reason' => trim($this->reason),
            ]);
            session()->flash('status', 'Leave requested.');
            $this->redirect(route('portal.leave'));
        } catch (PortalApiException $e) {
            if ($e->isSessionExpired()) {
                $this->failed($e, '');

                return;
            }
            if ($e->status === 422) {
                $map = ['leave_type_id' => 'type', 'start_date' => 'startDate', 'end_date' => 'endDate', 'reason' => 'reason'];
                foreach ($e->errors as $field => $messages) {
                    if (isset($map[$field]) && $messages !== []) {
                        $this->addError($map[$field], $messages[0]);
                    } elseif ($messages !== []) {
                        $this->generalError ??= $messages[0];
                    }
                }
                if ($e->errors === []) {
                    $this->generalError = $e->getMessage();
                }

                return;
            }
            $this->generalError = in_array($e->status, [403, 409], true) && $e->getMessage() !== ''
                ? $e->getMessage()
                : "Couldn't send the request. Please try again.";
        }
    }

    public function render(): View
    {
        $balances = [];
        if (! $this->noProfile && $this->errorMessage === null) {
            try {
                $balances = $this->api()->get('/me/leave-balances')['data'] ?? [];
            } catch (PortalApiException $e) {
                $this->failed($e, 'Something went wrong loading the form.');
            }
        }

        $selected = collect($balances)->firstWhere('leave_type.public_id', $this->type);
        $hint = null;
        if ($selected !== null && ($selected['is_paid'] ?? false) && $selected['remaining_days'] !== null
            && $this->validDate($this->startDate) && (int) substr($this->startDate, 0, 4) === (int) $selected['year']) {
            $hint = Format::days((int) $selected['remaining_days']).' left in '.$selected['year'];
        } elseif ($selected !== null && ! ($selected['is_paid'] ?? false)) {
            $hint = 'Unpaid leave';
        }

        $days = null;
        if ($this->validDate($this->startDate) && $this->validDate($this->endDate) && $this->endDate >= $this->startDate) {
            $days = CarbonImmutable::parse($this->startDate)->diffInDays(CarbonImmutable::parse($this->endDate)) + 1;
        }

        return view('livewire.portal.leave-form', ['balances' => $balances, 'hint' => $hint, 'days' => (int) $days ?: null]);
    }

    private function validateForm(): bool
    {
        $this->resetErrorBag();
        if ($this->type === '') {
            $this->addError('type', 'Choose a type of leave.');
        }
        if (! $this->validDate($this->startDate)) {
            $this->addError('startDate', 'Choose the first day.');
        }
        if (! $this->validDate($this->endDate)) {
            $this->addError('endDate', 'Choose the last day.');
        }
        if ($this->validDate($this->startDate) && $this->validDate($this->endDate)) {
            if ($this->endDate < $this->startDate) {
                $this->addError('endDate', 'The last day cannot be before the first day.');
            } elseif (substr($this->startDate, 0, 4) !== substr($this->endDate, 0, 4)) {
                $this->addError('endDate', 'A request must stay within one calendar year. Make one request for each year.');
            }
        }
        $length = mb_strlen(trim($this->reason));
        if ($length < self::MIN_REASON) {
            $this->addError('reason', 'Give a reason (at least '.self::MIN_REASON.' characters).');
        } elseif ($length > self::MAX_REASON) {
            $this->addError('reason', 'Use at most '.self::MAX_REASON.' characters.');
        }

        return $this->getErrorBag()->isEmpty();
    }

    private function validDate(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
            && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4));
    }
}
