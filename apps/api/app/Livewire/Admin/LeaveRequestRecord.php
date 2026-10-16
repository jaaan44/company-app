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
 * A leave request in the Backoffice (Phase 36B): details and history, and
 * Approve (optional note) / Reject (a reason) while pending, Cancel while
 * not final, through /api/v1/leave-requests/{id}/…. The API decides who
 * may act; its message is shown.
 */
class LeaveRequestRecord extends Component
{
    use CallsInternalApi;
    use WritesThroughApi;

    public const MIN_REASON = 3;

    public const MAX_TEXT = 1000;

    #[Locked]
    public string $publicId;

    #[Locked]
    public bool $notFound = false;

    /** The action being confirmed: approve, reject or cancel. */
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
        $this->confirming = in_array($action, ['approve', 'reject', 'cancel'], true) ? $action : null;
        $this->text = '';
        $this->generalError = null;
        $this->resetErrorBag();
    }

    public function dismiss(): void
    {
        $this->confirming = null;
        $this->resetErrorBag();
    }

    public function confirm(): void
    {
        $action = $this->confirming;
        if ($action === null) {
            return;
        }
        $text = trim($this->text);
        if ($action === 'reject' && mb_strlen($text) < self::MIN_REASON) {
            $this->addError('text', 'Give a reason (at least '.self::MIN_REASON.' characters).');

            return;
        }
        if (mb_strlen($text) > self::MAX_TEXT) {
            $this->addError('text', 'Use at most '.self::MAX_TEXT.' characters.');

            return;
        }

        $body = match ($action) {
            'approve' => ['note' => $text === '' ? null : $text],
            'reject' => ['reason' => $text],
            default => ['reason' => $text === '' ? null : $text],
        };
        try {
            $this->api()->send('POST', '/leave-requests/'.rawurlencode($this->publicId).'/'.$action, json: $body);
            $this->savedMessage = match ($action) {
                'approve' => 'Request approved.',
                'reject' => 'Request rejected.',
                default => 'Request cancelled.',
            };
            $this->toastNonce++;
            $this->confirming = null;
            $this->text = '';
        } catch (PortalApiException $e) {
            $this->confirming = null;
            $this->applyWriteFailure($e, [], "Couldn't send that. Please try again.");
        }
    }

    public function render(): View
    {
        $request = null;
        try {
            $request = $this->api()->get('/leave-requests/'.rawurlencode($this->publicId))['data'];
        } catch (PortalApiException $e) {
            if ($e->isNotFound()) {
                $this->notFound = true;
            } else {
                $this->failed($e, 'Something went wrong loading this request.', "You don't have access to this request.");
            }
        }
        $now = now(CompanyTimezone::value());
        $status = $request['status'] ?? null;

        return view('livewire.admin.leave-request-record', [
            'request' => $request,
            'companyDate' => $now->toDateString(),
            'utcOffset' => $now->format('P'),
            'canDecide' => $status === 'pending',
            'canCancel' => in_array($status, ['pending', 'approved'], true) && $this->may('leave-requests.manage'),
        ]);
    }
}
