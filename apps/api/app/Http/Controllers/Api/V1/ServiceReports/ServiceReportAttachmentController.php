<?php

namespace App\Http\Controllers\Api\V1\ServiceReports;

use App\Http\Controllers\Api\V1\ServiceReports\Concerns\AuthorizesServiceReportAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\ServiceReports\StoreServiceReportAttachmentRequest;
use App\Http\Resources\AttachmentResource;
use App\Models\Attachment;
use App\Models\ServiceReport;
use App\Services\Attachments\AttachmentStorage;
use App\Services\Audit\AuditLogger;
use App\Support\Attachments\AttachmentDisk;
use App\Support\Audit\AuditActions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * File attachments on a Service Report (Phase 18, DEC-041) — the
 * project's previously deferred shared attachment infrastructure,
 * authorized for this phase because Service Reports requires it and
 * Phase 19 (Incident Reports) is the concrete next consumer. Attachment
 * authorization always inherits its parent Service Report: whoever may
 * view the report may download its attachments; only the report's
 * creator or Administrator may upload/remove one, and only while the
 * report is still a `draft` (docs/phases/V1_PHASE_18_DEFINITION.md's
 * Attachment Authorization/Lifecycle).
 */
class ServiceReportAttachmentController extends Controller
{
    use AuthorizesServiceReportAccess;

    public function __construct(
        private readonly AttachmentStorage $attachmentStorage,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function store(StoreServiceReportAttachmentRequest $request, ServiceReport $serviceReport): JsonResponse
    {
        $this->authorizeManageDraft($request, $serviceReport);
        $this->assertEditable($serviceReport);

        $file = $request->file('file');

        $path = $this->attachmentStorage->store($file, $serviceReport->public_id);

        $attachment = DB::transaction(function () use ($request, $serviceReport, $file, $path) {
            $attachment = $serviceReport->attachments()->create([
                'original_filename' => $file->getClientOriginalName(),
                'storage_disk' => AttachmentDisk::name(),
                'storage_path' => $path,
                'mime_type' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
                'uploaded_by_user_id' => $request->user()->id,
            ]);

            $this->auditLogger->recordForRequest(
                $request,
                AuditActions::ATTACHMENT_UPLOADED,
                entityType: 'Attachment',
                entityPublicId: $attachment->public_id,
                after: [
                    'owner_type' => 'service_report',
                    'owner_public_id' => $serviceReport->public_id,
                ],
            );

            return $attachment;
        });

        return (new AttachmentResource($attachment->load('uploader.staff')))
            ->response()
            ->setStatusCode(201);
    }

    public function download(Request $request, ServiceReport $serviceReport, Attachment $attachment): StreamedResponse
    {
        $this->authorizeView($request, $serviceReport);
        $this->assertBelongsToReport($serviceReport, $attachment);

        return AttachmentDisk::filesystem()->download($attachment->storage_path, $attachment->original_filename);
    }

    public function destroy(Request $request, ServiceReport $serviceReport, Attachment $attachment): JsonResponse
    {
        $this->authorizeManageDraft($request, $serviceReport);
        $this->assertEditable($serviceReport);
        $this->assertBelongsToReport($serviceReport, $attachment);

        // The physical file is removed before the database row — see
        // the attachments migration's Attachment Architecture note for
        // why this ordering, not the reverse, is the safe one (it can
        // leave a dangling DB reference in a crash window, never an
        // orphaned file with no remaining reference).
        $this->attachmentStorage->delete($attachment->storage_path);

        $publicId = $attachment->public_id;

        DB::transaction(function () use ($request, $serviceReport, $attachment, $publicId) {
            $attachment->delete();

            $this->auditLogger->recordForRequest(
                $request,
                AuditActions::ATTACHMENT_DELETED,
                entityType: 'Attachment',
                entityPublicId: $publicId,
                before: [
                    'owner_type' => 'service_report',
                    'owner_public_id' => $serviceReport->public_id,
                ],
            );
        });

        return response()->json(status: 204);
    }

    private function assertEditable(ServiceReport $serviceReport): void
    {
        if (! $serviceReport->isEditable()) {
            abort(409, 'Attachments may only be added or removed while this service report is a draft.');
        }
    }

    /**
     * {attachment:public_id} resolves globally via implicit route model
     * binding (withoutScopedBindings() — ServiceReport has no singular
     * `attachment()` relation for Laravel to guess, mirroring Phase
     * 10/16/17's identical two-consecutive-Eloquent-parameter fix); this
     * guards against addressing a real Attachment through a different
     * Service Report's URL.
     */
    private function assertBelongsToReport(ServiceReport $serviceReport, Attachment $attachment): void
    {
        if ($attachment->service_report_id !== $serviceReport->id) {
            abort(404);
        }
    }
}
