<?php

namespace App\Livewire\Admin;

use App\Livewire\Admin\Concerns\PicksStaff;
use App\Livewire\Portal\Concerns\CallsInternalApi;
use App\Livewire\Portal\Concerns\WritesThroughApi;
use App\Services\Portal\PortalApiException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Work → Work logs (Phase 36C): everyone's logged work the API lets this
 * person see, newest first, filtered by person and dates. With
 * `work-logs.manage`, correct a log's date, time and description in
 * place, or remove it. The person, task and project stay fixed (the API
 * prohibits changing them).
 */
class WorkLogs extends Component
{
    use CallsInternalApi;
    use PicksStaff;
    use WritesThroughApi;

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    public int $page = 1;

    #[Locked]
    public ?string $editing = null;

    public string $workDate = '';

    public string $hours = '0';

    public string $minutes = '0';

    public string $description = '';

    public ?string $savedMessage = null;

    public int $toastNonce = 0;

    public function updated(string $property): void
    {
        if (in_array($property, ['from', 'to'], true)) {
            $this->page = 1;
        }
    }

    protected function staffChosen(): void
    {
        $this->page = 1;
    }

    public function goToPage(int $page): void
    {
        $this->page = max(1, $page);
    }

    public function edit(string $publicId): void
    {
        $this->cancel();
        if (! preg_match('/^[0-9A-Za-z]{1,64}$/', $publicId)) {
            return;
        }
        try {
            $log = $this->api()->get('/work-logs/'.$publicId)['data'];
        } catch (PortalApiException $e) {
            $this->applyWriteFailure($e, [], 'Something went wrong. Please try again.');

            return;
        }
        $this->editing = $publicId;
        $this->workDate = (string) $log['work_date'];
        $this->hours = (string) intdiv((int) $log['duration_minutes'], 60);
        $this->minutes = (string) ((int) $log['duration_minutes'] % 60);
        $this->description = (string) $log['description'];
    }

    public function cancel(): void
    {
        $this->editing = null;
        $this->generalError = null;
        $this->resetErrorBag();
    }

    public function save(): void
    {
        if ($this->editing === null) {
            return;
        }
        $this->generalError = null;
        $this->resetErrorBag();
        $total = ctype_digit($this->hours) && ctype_digit($this->minutes) ? (int) $this->hours * 60 + (int) $this->minutes : 0;
        if ($total < 1 || $total > 1440) {
            $this->addError('hours', 'Give a time between 1 minute and 24 hours.');
        }
        if (trim($this->description) === '' || mb_strlen($this->description) > 2000) {
            $this->addError('description', 'Describe the work (at most 2,000 characters).');
        }
        if ($this->workDate === '') {
            $this->addError('workDate', 'Choose the date.');
        }
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }
        try {
            $this->api()->send('PATCH', '/work-logs/'.$this->editing, json: [
                'work_date' => $this->workDate, 'duration_minutes' => $total, 'description' => trim($this->description),
            ]);
            $this->cancel();
            $this->toast('Work log corrected.');
        } catch (PortalApiException $e) {
            $this->applyWriteFailure($e, ['work_date' => 'workDate', 'duration_minutes' => 'hours', 'description' => 'description'], "Couldn't save. Please try again.");
        }
    }

    public function remove(string $publicId): void
    {
        $this->generalError = null;
        if (! preg_match('/^[0-9A-Za-z]{1,64}$/', $publicId)) {
            return;
        }
        try {
            $this->api()->send('DELETE', '/work-logs/'.$publicId);
            $this->toast('Work log removed.');
        } catch (PortalApiException $e) {
            $this->applyWriteFailure($e, [], "Couldn't remove it. Please try again.");
        }
    }

    private function toast(string $message): void
    {
        $this->savedMessage = $message;
        $this->toastNonce++;
    }

    public function render(): View
    {
        $result = null;
        $date = fn (string $value) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
        try {
            $result = $this->api()->get('/work-logs', [
                'staff' => $this->staffId === '' ? null : $this->staffId,
                'from' => $date($this->from), 'to' => $date($this->to), 'per_page' => 25, 'page' => $this->page,
            ]);
        } catch (PortalApiException $e) {
            $this->failed($e, 'Something went wrong loading work logs.', "You don't have access to work logs.");
        }

        return view('livewire.admin.work-logs', [
            'logs' => $result['data'] ?? [],
            'meta' => $result['meta'] ?? null,
            'people' => $this->staffMatches(),
            'canManage' => $this->may('work-logs.manage'),
        ]);
    }
}
