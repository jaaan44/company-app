<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A single item in the unified Scheduler projection
 * (`GET /api/v1/schedule`, Phase 17 — App\Http\Controllers\Api\V1\
 * Scheduling\ScheduleController). Unlike every other Resource in this
 * codebase, `$this->resource` here is already a plain, pre-normalized
 * array (built by the controller from one of four different Eloquent
 * models — ScheduleEntry/Task/LeaveRequest/ProjectMilestone), not an
 * Eloquent model — the normalization step itself is what makes these
 * four genuinely different source records presentable as one homogeneous
 * list. See ScheduleController for exactly what each source type
 * contributes.
 */
class ScheduleItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
