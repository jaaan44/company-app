<?php

namespace App\Http\Resources;

use App\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A single attachment's metadata (Phase 18, DEC-041). Never exposes
 * `storage_disk`/`storage_path`/the internal numeric id — a client
 * downloads the file through the dedicated authenticated/authorized
 * download endpoint (ServiceReportAttachmentController::download()),
 * addressed only by this resource's `public_id`, never a stored path.
 *
 * @mixin Attachment
 */
class AttachmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $uploaderStaff = $this->uploader?->staff;

        return [
            'public_id' => $this->public_id,
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'uploaded_by' => $uploaderStaff === null ? null : [
                'public_id' => $uploaderStaff->public_id,
                'employee_number' => $uploaderStaff->employee_number,
                'display_name' => $uploaderStaff->displayName(),
            ],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
