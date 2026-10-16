<?php

namespace App\Livewire\Admin;

use App\Livewire\Portal\Concerns\CallsInternalApi;
use App\Livewire\Portal\Concerns\WritesThroughApi;
use App\Services\Portal\PortalApiException;
use App\Support\CompanyTimezone;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A service report in the Backoffice (Phase 36C): the report, its files
 * and history, and — while submitted — Mark reviewed (an optional note,
 * `POST …/review`) or Reject (a reason). The API decides who may.
 */
class ServiceReportRecord extends Component
{
    use CallsInternalApi;
    use WritesThroughApi;

    #[Locked]
    public string $publicId;

    #[Locked]
    public bool $notFound = false;

    #[Locked]
    public ?string $confirming = null;

    public string $text = '';

    public ?string $savedMessage = null;

    public int $toastNonce = 0;

    public function mount(string $publicId): void
    {
        $this->publicId = $publicId;
    }

    public function ask(string $action): void
    {
        $this->confirming = in_array($action, ['review', 'reject'], true) ? $action : null;
        $this->text = '';
        $this->generalError = null;
        $this->resetErrorBag();
    }

    public function dismiss(): void
    {
        $this->confirming = null;
    }

    public function confirm(): void
    {
        $action = $this->confirming;
        if ($action === null) {
            return;
        }
        $text = trim($this->text);
        if ($action === 'reject' && mb_strlen($text) < 3) {
            $this->addError('text', 'Give a reason (at least 3 characters).');

            return;
        }
        if (mb_strlen($text) > 1000) {
            $this->addError('text', 'Use at most 1,000 characters.');

            return;
        }
        try {
            $this->api()->send('POST', '/service-reports/'.rawurlencode($this->publicId).'/'.$action,
                json: $action === 'reject' ? ['reason' => $text] : ['note' => $text === '' ? null : $text]);
            $this->savedMessage = $action === 'reject' ? 'Report rejected.' : 'Report marked as reviewed.';
            $this->toastNonce++;
            $this->confirming = null;
        } catch (PortalApiException $e) {
            $this->confirming = null;
            $this->applyWriteFailure($e, [], "Couldn't send that. Please try again.");
        }
    }

    public function render(): View
    {
        $report = null;
        try {
            $report = $this->api()->get('/service-reports/'.rawurlencode($this->publicId))['data'];
        } catch (PortalApiException $e) {
            $e->isNotFound() ? $this->notFound = true : $this->failed($e, 'Something went wrong loading the report.', "You don't have access to this report.");
        }

        return view('livewire.admin.service-report-record', ['report' => $report, 'timezone' => CompanyTimezone::value()]);
    }
}
