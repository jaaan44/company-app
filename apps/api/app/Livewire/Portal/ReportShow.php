<?php

namespace App\Livewire\Portal;

use App\Livewire\Portal\Concerns\CallsInternalApi;
use App\Services\Portal\PortalApiException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Portal service report or incident (Phase 34E): the mobile detail
 * screens (Phase 33, R-4/R-6) — every field, the history in company
 * time, and the photos and files.
 *
 * - **Service report**, my draft: Edit, attach, remove, **Submit** and
 *   **Delete draft**; my rejected report: **Return to draft**.
 * - **Incident**, mine and still reported: Edit, attach, remove.
 *
 * Every action is confirmed first and done by the API, whose rules decide.
 * Files (W-8): JPEG, PNG or PDF up to 10 MB, chosen in the browser and
 * forwarded to the API's attachment endpoint; photos open in the browser,
 * PDFs download, both through the portal's authorized download route.
 */
class ReportShow extends Component
{
    use CallsInternalApi;
    use WithFileUploads;

    public const MAX_KB = 10240;

    public const MIMES = ['jpg', 'jpeg', 'png', 'pdf'];

    #[Locked]
    public string $publicId = '';

    #[Locked]
    public string $kind = 'service';

    #[Locked]
    public ?string $myStaffId = null;

    /** @var TemporaryUploadedFile|null */
    public $file = null;

    public ?string $actionMessage = null;

    public ?string $doneMessage = null;

    public function mount(string $publicId, string $kind = 'service'): void
    {
        $this->publicId = $publicId;
        $this->kind = $kind === 'incidents' ? 'incidents' : 'service';
        try {
            $this->myStaffId = $this->api()->get('/me/profile')['data']['staff']['public_id'] ?? null;
        } catch (PortalApiException $e) {
            $this->failed($e, 'Something went wrong loading this report.');
        }
        if ($this->myStaffId === null && $this->errorMessage === null) {
            $this->noProfile = true;
        }
    }

    public function addFile(): void
    {
        $this->actionMessage = null;
        $this->doneMessage = null;
        $this->validate(
            ['file' => ['required', 'file', 'mimes:'.implode(',', self::MIMES), 'max:'.self::MAX_KB]],
            [
                'file.required' => 'Choose a photo or PDF first.',
                'file.mimes' => 'Choose a JPEG, PNG or PDF file.',
                'file.max' => 'The file is larger than 10 MB.',
                'file.uploaded' => 'The file is larger than 10 MB.',
            ],
        );

        try {
            $this->api()->upload(
                $this->path().'/attachments',
                'file',
                $this->file->getRealPath(),
                $this->file->getClientOriginalName(),
                $this->file->getMimeType(),
            );
            $this->doneMessage = 'File added.';
        } catch (PortalApiException $e) {
            if ($e->isSessionExpired()) {
                $this->failed($e, '');

                return;
            }
            $this->actionMessage = $e->firstErrorFor('file')
                ?? (in_array($e->status, [403, 409, 422], true) && $e->getMessage() !== '' ? $e->getMessage() : "Couldn't add the file. Please try again.");
        } finally {
            $this->file?->delete();
            $this->file = null;
        }
    }

    public function removeFile(string $attachmentId): void
    {
        if (preg_match('/^[0-9A-Za-z]{1,64}$/', $attachmentId) === 1) {
            $this->act('DELETE', '/attachments/'.rawurlencode($attachmentId), 'File removed.');
        }
    }

    public function submitReport(): void
    {
        $this->act('POST', '/submit', 'Report submitted.');
    }

    public function returnToDraft(): void
    {
        $this->act('POST', '/return-to-draft', 'Returned to draft.');
    }

    public function deleteDraft(): void
    {
        if ($this->act('DELETE', '', 'Draft deleted.')) {
            session()->flash('status', 'Draft deleted.');
            $this->redirect(route('portal.reports'));
        }
    }

    public function render(): View
    {
        $this->errorMessage = null;
        $report = null;
        $companyDay = null;

        if (! $this->noProfile) {
            try {
                $report = $this->api()->get($this->path())['data'] ?? null;
                $companyDay = $this->api()->get($this->kind === 'incidents' ? '/incident-reports' : '/service-reports', ['per_page' => 1])['meta']['company_day'] ?? null;
            } catch (PortalApiException $e) {
                if ($report === null) {
                    if ($e->isNotFound()) {
                        $this->errorMessage = 'This report is no longer available.';
                    } else {
                        $this->failed($e, 'Something went wrong loading this report.', forbidden: "You don't have access to this report.");
                    }
                } elseif ($e->isSessionExpired()) {
                    $report = null;
                    $this->failed($e, '');
                }
            }
        }

        $status = $report['status'] ?? null;
        $mine = $report !== null && (($report[$this->kind === 'incidents' ? 'reporter' : 'creator']['public_id'] ?? null) === $this->myStaffId);
        $canEdit = $mine && $status === ($this->kind === 'incidents' ? 'reported' : 'draft');

        return view('livewire.portal.report-show', [
            'report' => $report,
            'companyDay' => $companyDay,
            'canEdit' => $canEdit,
            'canReturn' => $this->kind === 'service' && $mine && $status === 'rejected',
        ]);
    }

    private function act(string $method, string $suffix, string $done): bool
    {
        $this->actionMessage = null;
        $this->doneMessage = null;

        try {
            $this->api()->send($method, $this->path().$suffix);
            $this->doneMessage = $done;

            return true;
        } catch (PortalApiException $e) {
            if ($e->isSessionExpired()) {
                $this->failed($e, '');

                return false;
            }
            $this->actionMessage = match (true) {
                $e->isNotFound() => 'This is no longer available.',
                in_array($e->status, [403, 409, 422], true) && $e->getMessage() !== '' => $e->getMessage(),
                default => "Couldn't do that. Please try again.",
            };

            return false;
        }
    }

    private function path(): string
    {
        return ($this->kind === 'incidents' ? '/incident-reports/' : '/service-reports/').rawurlencode($this->publicId);
    }
}
