<?php

namespace App\Livewire\Admin;

use App\Livewire\Admin\Concerns\PicksStaff;
use App\Livewire\Portal\Concerns\CallsInternalApi;
use App\Livewire\Portal\Concerns\WritesThroughApi;
use App\Services\Portal\PortalApiException;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Request leave for someone (Phase 36B), through POST
 * /api/v1/leave-requests (`leave-requests.manage`): the person, the type,
 * the dates and a reason. The API checks the balance, overlaps and the
 * one-year rule, and the request starts as Pending.
 */
class LeaveRequestForm extends Component
{
    use CallsInternalApi;
    use PicksStaff;
    use WritesThroughApi;

    public string $typeId = '';

    public string $startDate = '';

    public string $endDate = '';

    public string $reason = '';

    public function updatedStartDate(): void
    {
        if ($this->endDate === '' || $this->endDate < $this->startDate) {
            $this->endDate = $this->startDate;
        }
    }

    public function save(): void
    {
        $this->generalError = null;
        $this->resetErrorBag();
        $reason = trim($this->reason);
        foreach ([
            'staffSearch' => [$this->staffId === '', 'Choose the person.'],
            'typeId' => [$this->typeId === '', 'Choose a type of leave.'],
            'startDate' => [$this->startDate === '', 'Choose the first day.'],
            'endDate' => [$this->endDate === '', 'Choose the last day.'],
            'reason' => [mb_strlen($reason) < 3 || mb_strlen($reason) > 1000, 'Give a reason (3 to 1,000 characters).'],
        ] as $field => [$failed, $message]) {
            if ($failed) {
                $this->addError($field, $message);
            }
        }
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        try {
            $request = $this->api()->send('POST', '/leave-requests', json: [
                'staff_id' => $this->staffId, 'leave_type_id' => $this->typeId,
                'start_date' => $this->startDate, 'end_date' => $this->endDate, 'reason' => $reason,
            ])['data'];
            session()->flash('status', 'Leave requested for '.$this->staffName.'.');
            $this->redirect(route('admin.leave-requests.show', $request['public_id']), navigate: true);
        } catch (PortalApiException $e) {
            $this->applyWriteFailure($e, [
                'staff_id' => 'staffSearch', 'leave_type_id' => 'typeId', 'start_date' => 'startDate', 'end_date' => 'endDate', 'reason' => 'reason',
            ], "Couldn't request the leave. Please try again.");
        }
    }

    public function render(): View
    {
        $types = [];
        try {
            $types = $this->api()->get('/leave-types', ['status' => 'active', 'per_page' => 100])['data'] ?? [];
        } catch (PortalApiException $e) {
            $this->failed($e, 'Something went wrong loading the leave types.', "You don't have access to leave.");
        }

        return view('livewire.admin.leave-request-form', ['types' => $types, 'people' => $this->staffMatches()]);
    }
}
