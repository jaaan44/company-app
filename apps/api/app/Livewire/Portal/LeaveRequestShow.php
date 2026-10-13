<?php

namespace App\Livewire\Portal;

use App\Livewire\Portal\Concerns\CallsInternalApi;
use App\Services\Portal\PortalApiException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Portal leave request (Phase 34C): the mobile request screen (Phase 31)
 * for **my** request (`GET /me/leave-requests/{id}`, Cancel) or a **team**
 * request (`GET /leave-requests/{id}`, Approve / Reject). Every action is
 * confirmed first and done by the API, whose own rules decide: Cancel is
 * offered for a pending request or an approved one not yet started (by
 * the company day, R-2b/R-6); Approve and Reject only while pending (R-7).
 */
class LeaveRequestShow extends Component
{
    use CallsInternalApi;

    public const MIN_REASON = 3;

    public const MAX_TEXT = 1000;

    #[Locked]
    public string $publicId = '';

    #[Locked]
    public string $viewer = 'mine';

    /** The action being confirmed: 'cancel', 'approve' or 'reject'. */
    public ?string $confirming = null;

    public string $text = '';

    public ?string $actionMessage = null;

    public ?string $doneMessage = null;

    public function mount(string $publicId, string $viewer = 'mine'): void
    {
        $this->publicId = $publicId;
        $this->viewer = $viewer === 'team' ? 'team' : 'mine';
    }

    public function ask(string $action): void
    {
        $allowed = $this->viewer === 'mine' ? ['cancel'] : ['approve', 'reject'];
        $this->confirming = in_array($action, $allowed, true) ? $action : null;
        $this->text = '';
        $this->actionMessage = null;
        $this->resetErrorBag();
    }

    public function dismiss(): void
    {
        $this->confirming = null;
        $this->text = '';
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

        [$path, $body, $done] = match ($action) {
            'cancel' => ['/me/leave-requests/'.rawurlencode($this->publicId).'/cancel', ['reason' => $text === '' ? null : $text], 'Request cancelled.'],
            'approve' => ['/leave-requests/'.rawurlencode($this->publicId).'/approve', ['note' => $text === '' ? null : $text], 'Request approved.'],
            default => ['/leave-requests/'.rawurlencode($this->publicId).'/reject', ['reason' => $text], 'Request rejected.'],
        };

        try {
            $this->api()->send('POST', $path, json: $body);
            $this->doneMessage = $done;
            $this->confirming = null;
            $this->text = '';
        } catch (PortalApiException $e) {
            if ($e->isSessionExpired()) {
                $this->failed($e, '');

                return;
            }
            $this->confirming = null;
            $this->actionMessage = match (true) {
                $e->status === 422 => $e->firstErrorFor('reason') ?? $e->firstErrorFor('note') ?? $e->getMessage(),
                $e->isNotFound() => 'This request is no longer available.',
                in_array($e->status, [403, 409], true) && $e->getMessage() !== '' => $e->getMessage(),
                default => "Couldn't send that. Please try again.",
            };
        }
    }

    public function render(): View
    {
        $this->errorMessage = null;
        $request = null;
        $companyDay = null;
        $path = $this->viewer === 'team'
            ? '/leave-requests/'.rawurlencode($this->publicId)
            : '/me/leave-requests/'.rawurlencode($this->publicId);

        try {
            $request = $this->api()->get($path)['data'] ?? null;
            $companyDay = $this->viewer === 'team'
                ? $this->api()->get('/me/team-leave-requests', ['per_page' => 1])['meta']['company_day'] ?? null
                : $this->api()->get('/me/leave-requests', ['per_page' => 1])['meta']['company_day'] ?? null;
        } catch (PortalApiException $e) {
            if ($request === null) {
                if ($e->isNotFound()) {
                    $this->errorMessage = 'This request is no longer available.';
                } else {
                    $this->failed($e, 'Something went wrong loading this request.', forbidden: $this->viewer === 'team' ? "You don't have access to this request." : null);
                }
            } elseif ($e->isSessionExpired()) {
                $request = null;
                $this->failed($e, '');
            }
        }

        $today = $companyDay['date'] ?? null;
        $status = $request['status'] ?? null;

        return view('livewire.portal.leave-request-show', [
            'request' => $request,
            'companyDay' => $companyDay,
            'canCancel' => $this->viewer === 'mine' && $today !== null
                && ($status === 'pending' || ($status === 'approved' && $request['start_date'] > $today)),
            'canDecide' => $this->viewer === 'team' && $status === 'pending',
        ]);
    }
}
