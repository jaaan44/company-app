<?php

namespace App\Models;

use App\Enums\AttachmentOwnerType;
use Database\Factories\AttachmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A single uploaded file's metadata (Phase 18 — Service Reports, DEC-041)
 * — the shared attachment infrastructure `03_DATABASE_MODEL.md` §1
 * deferred at Phase 0, now built because Service Reports requires it and
 * Phase 19 (Incident Reports) is the concrete next consumer. The actual
 * file bytes live on a Laravel filesystem disk (see
 * App\Support\Attachments\AttachmentDisk), never in this table — this
 * row is metadata plus a storage pointer only.
 *
 * `owner_type` is a typed, non-polymorphic discriminator (never a raw
 * PHP class name / Laravel `attachable_type`), paired with a genuine
 * foreign key per owner type — `service_report_id` today. See
 * docs/phases/V1_PHASE_18_DEFINITION.md's Attachment Architecture.
 *
 * @property int $id
 * @property string $public_id
 * @property AttachmentOwnerType $owner_type
 * @property int $service_report_id
 * @property string $original_filename
 * @property string $storage_disk
 * @property string $storage_path
 * @property string $mime_type
 * @property int $size_bytes
 * @property int|null $uploaded_by_user_id
 */
#[Fillable([
    'owner_type', 'service_report_id', 'original_filename', 'storage_disk',
    'storage_path', 'mime_type', 'size_bytes', 'uploaded_by_user_id',
])]
class Attachment extends Model
{
    /** @use HasFactory<AttachmentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'owner_type' => AttachmentOwnerType::class,
            'size_bytes' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Attachment $attachment): void {
            $attachment->public_id ??= (string) Str::ulid();
            $attachment->owner_type ??= AttachmentOwnerType::ServiceReport;
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @return BelongsTo<ServiceReport, $this>
     */
    public function serviceReport(): BelongsTo
    {
        return $this->belongsTo(ServiceReport::class);
    }

    /**
     * The User who uploaded this file — accountability only. Nullable —
     * mirrors every other *_by_user_id column in this codebase.
     *
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
