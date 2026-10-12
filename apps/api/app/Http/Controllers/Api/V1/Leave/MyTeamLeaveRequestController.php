<?php

namespace App\Http\Controllers\Api\V1\Leave;

use App\Enums\LeaveRequestStatus;
use App\Http\Controllers\Api\V1\StaffOperations\Concerns\RequiresLinkedStaff;
use App\Http\Controllers\Controller;
use App\Http\Resources\LeaveRequestResource;
use App\Models\LeaveRequest;
use App\Models\Staff;
use App\Support\CompanyDay;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * "Team requests" for the mobile app (Phase 31, R-3): the leave requests
 * of the caller's own direct reports (`staff.manager_id`) — never the
 * company-wide supervisory list, so an Administrator here sees only the
 * people they manage too. Needs a linked Staff record (the `/me` 403).
 * Without `leave-requests.view` (Manager; Administrator via Gate::before)
 * the answer is an empty list with `meta.can_review: false` rather than a
 * 403 — the app asks on every Leave screen load, and a 403 would cost an
 * `/auth/me` re-check each time (Phase 31, R-3). Decisions use the existing `/leave-requests/{id}/approve|reject`, whose
 * authority rules are unchanged.
 */
class MyTeamLeaveRequestController extends Controller
{
    use RequiresLinkedStaff;

    private const WITH_RELATIONS = ['staff', 'leaveType', 'actions.actor.staff', 'creator.staff'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['sometimes', 'string', Rule::in([...array_column(LeaveRequestStatus::cases(), 'value'), 'all'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $staff = $this->resolveAuthenticatedStaff($request);

        $canReview = $request->user()?->can('leave-requests.view') ?? false;

        $status = $request->string('status', LeaveRequestStatus::Pending->value)->toString();

        /** @var Builder<LeaveRequest> $team */
        $team = LeaveRequest::query()
            ->whereHas('staff', fn (Builder $query) => $query->where('manager_id', $staff->id))
            // No review rights: nothing, whoever reports to them.
            ->when(! $canReview, fn (Builder $query) => $query->whereRaw('1 = 0'));

        $query = (clone $team)
            ->with(self::WITH_RELATIONS)
            ->when($status !== 'all', fn (Builder $query) => $query->where('status', $status));

        // Pending: soonest first — what needs deciding next. Anything else:
        // newest first, like the requester's own list. `id` keeps paging
        // stable when start dates tie.
        if ($status === LeaveRequestStatus::Pending->value) {
            $query->orderBy('start_date')->orderBy('id');
        } else {
            $query->orderByDesc('start_date')->orderByDesc('id');
        }

        return LeaveRequestResource::collection($query->paginate($request->integer('per_page', 50)))
            ->additional(['meta' => [
                'can_review' => $canReview,
                'direct_reports_count' => $canReview ? Staff::query()->where('manager_id', $staff->id)->count() : 0,
                'pending_count' => (clone $team)->where('status', LeaveRequestStatus::Pending)->count(),
                'company_day' => CompanyDay::meta(),
            ]]);
    }
}
