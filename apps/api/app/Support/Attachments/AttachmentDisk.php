<?php

namespace App\Support\Attachments;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * The single point of access for "which filesystem disk do attachments
 * live on" (Phase 18 — Service Reports, `config('attachments.disk')`).
 * Nothing else in the codebase should call `Storage::disk('local')` (or
 * any other disk name) directly for an attachment — this keeps the one
 * genuinely cross-cutting piece of attachment storage configuration in
 * exactly one place, mirroring App\Support\CompanyTimezone's identical
 * Phase 17 discipline for scheduling.
 */
final class AttachmentDisk
{
    public static function name(): string
    {
        return config('attachments.disk', 'local');
    }

    public static function filesystem(): Filesystem
    {
        return Storage::disk(self::name());
    }
}
