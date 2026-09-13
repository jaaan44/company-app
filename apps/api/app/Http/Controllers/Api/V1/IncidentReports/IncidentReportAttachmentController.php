<?php

namespace App\Http\Controllers\Api\V1\IncidentReports;

use App\Enums\AttachmentOwnerType;
use App\Http\Controllers\Api\V1\IncidentReports\Concerns\AuthorizesIncidentReportAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\IncidentReports\StoreIncidentReportAttachmentRequest;
use App\Http\Resources\AttachmentResource;
use App\Models\Attachment;
use App\Models\IncidentReport;
use App\Services\Attachments\AttachmentStorage;
use App\Support\Attachments\AttachmentDisk;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * File attachments on an Incident Report (Phase 19, DEC-042) — the
 * second authorized consumer of the shared Phase 18 attachment
 * infrastructure (`AttachmentOwnerType::IncidentReport`,
 * `attachments.incident_report_id`). Attachment authorization always
 * inherits the parent Incident Report's own visibility; mutation
 * (upload/removal) is additionally restricted to whoever currently has
 * content-management authority over the report AND only while it is
 * `reported`/`under_investigation` (docs/phases/
 * V1_PHASE_19_DEFINITION.md's Attachment Authorization). Mirrors
 * ServiceReportAttachmentController's structure exactly; no existing
 * Service Report attachment behavior is altered.
 */
class IncidentReportAttachmentController extends Controller
{
    use AuthorizesIncidentReportAccess;

    public function __construct(private readonly AttachmentStorage $attachmentStorage) {}

    public function store(StoreIncidentReportAttachmentRequest $request, IncidentReport $incidentReport): JsonResponse
    {
        $this->authorizeManageContent($request, $incidentReport);
        $this->assertMutable($incidentReport);

        $file = $request->file('file');

        $path = $this->attachmentStorage->store($file, $incidentReport->public_id, 'incident-reports');

        $attachment = $incidentReport->attachments()->create([
            'owner_type' => AttachmentOwnerType::IncidentReport,
            'original_filename' => $file->getClientOriginalName(),
            'storage_disk' => AttachmentDisk::name(),
            'storage_path' => $path,
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'uploaded_by_user_id' => $request->user()->id,
        ]);

        return (new AttachmentResource($attachment->load('uploader.staff')))
            ->response()
            ->setStatusCode(201);
    }

    public function download(Request $request, IncidentReport $incidentReport, Attachment $attachment): StreamedResponse
    {
        $this->authorizeView($request, $incidentReport);
        $this->assertBelongsToReport($incidentReport, $attachment);

        return AttachmentDisk::filesystem()->download($attachment->storage_path, $attachment->original_filename);
    }

    public function destroy(Request $request, IncidentReport $incidentReport, Attachment $attachment): JsonResponse
    {
        $this->authorizeManageContent($request, $incidentReport);
        $this->assertMutable($incidentReport);
        $this->assertBelongsToReport($incidentReport, $attachment);

        // The physical file is removed before the database row —
        // mirrors ServiceReportAttachmentController::destroy()'s
        // identical consistency-boundary discipline (Phase 18).
        $this->attachmentStorage->delete($attachment->storage_path);

        DB::transaction(fn () => $attachment->delete());

        return response()->json(status: 204);
    }

    private function assertMutable(IncidentReport $incidentReport): void
    {
        if (! $incidentReport->status->isContentMutable()) {
            abort(409, 'Attachments may only be added or removed while this incident report is reported or under investigation; reopen it first.');
        }
    }

    /**
     * {attachment:public_id} resolves globally via implicit route model
     * binding (withoutScopedBindings() — IncidentReport has no singular
     * `attachment()` relation for Laravel to guess, mirroring Service
     * Reports' identical fix, Phase 18); this guards against addressing
     * a real Attachment through a different Incident Report's URL.
     */
    private function assertBelongsToReport(IncidentReport $incidentReport, Attachment $attachment): void
    {
        if ($attachment->incident_report_id !== $incidentReport->id) {
            abort(404);
        }
    }
}
