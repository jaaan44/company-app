<?php

namespace Tests\Feature\Api\V1\Leave;

use App\Models\LeaveRequest;
use App\Models\Staff;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 31 (R-3): GET /me/team-leave-requests — the caller's direct
 * reports' requests only, for `leave-requests.view` holders.
 */
class MyTeamLeaveRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config(['scheduling.company_timezone' => 'Asia/Manila']);
        $this->travelTo(Carbon::parse('2026-10-14 23:00:00', 'UTC'));
    }

    private function actingAsLinked(User $user): Staff
    {
        $staff = Staff::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        return $staff;
    }

    private function reportOf(Staff $manager): Staff
    {
        return Staff::factory()->create(['manager_id' => $manager->id]);
    }

    /** @param array<string, mixed> $attributes */
    private function requestFor(Staff $staff, string $start, array $attributes = []): LeaveRequest
    {
        return LeaveRequest::factory()->create([
            'staff_id' => $staff->id,
            'start_date' => $start,
            'end_date' => $start,
            'total_days' => 1,
            ...$attributes,
        ]);
    }

    public function test_a_manager_sees_only_their_direct_reports_pending_requests_soonest_first(): void
    {
        $manager = $this->actingAsLinked(User::factory()->manager()->create());
        $report = $this->reportOf($manager);
        $otherReport = $this->reportOf($manager);
        $someoneElse = Staff::factory()->create(['manager_id' => Staff::factory()->create()->id]);

        $later = $this->requestFor($report, '2026-11-10');
        $sooner = $this->requestFor($otherReport, '2026-10-20');
        $this->requestFor($report, '2026-10-18', ['status' => 'approved']);
        $this->requestFor($someoneElse, '2026-10-16');
        $this->requestFor($manager, '2026-10-16');

        $this->getJson('/api/v1/me/team-leave-requests')
            ->assertOk()
            ->assertJsonPath('data.*.public_id', [$sooner->public_id, $later->public_id])
            ->assertJsonPath('data.0.staff.public_id', $otherReport->public_id)
            ->assertJsonPath('meta.can_review', true)
            ->assertJsonPath('meta.direct_reports_count', 2)
            ->assertJsonPath('meta.pending_count', 2)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.company_day.date', '2026-10-15')
            ->assertJsonPath('meta.company_day.utc_offset', '+08:00');
    }

    public function test_other_statuses_are_newest_first_and_all_lists_everything(): void
    {
        $manager = $this->actingAsLinked(User::factory()->manager()->create());
        $report = $this->reportOf($manager);
        $older = $this->requestFor($report, '2026-09-01', ['status' => 'approved']);
        $newer = $this->requestFor($report, '2026-12-01', ['status' => 'approved']);
        $rejected = $this->requestFor($report, '2026-10-01', ['status' => 'rejected']);
        $pending = $this->requestFor($report, '2026-10-25');

        $this->getJson('/api/v1/me/team-leave-requests?status=approved')
            ->assertOk()
            ->assertJsonPath('data.*.public_id', [$newer->public_id, $older->public_id])
            ->assertJsonPath('meta.pending_count', 1);

        $this->getJson('/api/v1/me/team-leave-requests?status=all')
            ->assertOk()
            ->assertJsonPath('data.*.public_id', [
                $newer->public_id, $pending->public_id, $rejected->public_id, $older->public_id,
            ]);
    }

    public function test_ties_are_broken_by_id_in_the_sql(): void
    {
        $manager = $this->actingAsLinked(User::factory()->manager()->create());
        $report = $this->reportOf($manager);
        $this->requestFor($report, '2026-10-20');
        // The paginator skips the select when the count is 0.
        $this->requestFor($report, '2026-10-22', ['status' => 'cancelled']);

        DB::enableQueryLog();
        $this->getJson('/api/v1/me/team-leave-requests')->assertOk();
        $this->getJson('/api/v1/me/team-leave-requests?status=cancelled')->assertOk();
        $queries = collect(DB::getQueryLog())->pluck('query');

        $this->assertTrue($queries->contains(fn (string $q) => str_contains($q, 'order by "start_date" asc, "id" asc')));
        $this->assertTrue($queries->contains(fn (string $q) => str_contains($q, 'order by "start_date" desc, "id" desc')));
    }

    public function test_an_administrator_sees_only_their_own_direct_reports(): void
    {
        $admin = $this->actingAsLinked(User::factory()->administrator()->create());
        $report = $this->reportOf($admin);
        $mine = $this->requestFor($report, '2026-10-20');
        $this->requestFor(Staff::factory()->create(), '2026-10-21');

        $this->getJson('/api/v1/me/team-leave-requests')
            ->assertOk()
            ->assertJsonPath('data.*.public_id', [$mine->public_id])
            ->assertJsonPath('meta.can_review', true)
            ->assertJsonPath('meta.direct_reports_count', 1);
    }

    public function test_a_manager_with_no_reports_gets_an_empty_list(): void
    {
        $this->actingAsLinked(User::factory()->manager()->create());
        $this->requestFor(Staff::factory()->create(), '2026-10-21');

        $this->getJson('/api/v1/me/team-leave-requests')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.direct_reports_count', 0)
            ->assertJsonPath('meta.pending_count', 0);
    }

    public function test_a_staff_member_without_review_rights_gets_nothing(): void
    {
        $staff = $this->actingAsLinked(User::factory()->staff()->create());
        // Even with someone pointing at them as manager.
        $this->requestFor($this->reportOf($staff), '2026-10-20');

        $this->getJson('/api/v1/me/team-leave-requests?status=all')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.can_review', false)
            ->assertJsonPath('meta.direct_reports_count', 0)
            ->assertJsonPath('meta.pending_count', 0)
            ->assertJsonPath('meta.total', 0);
    }

    public function test_a_user_without_a_staff_profile_gets_the_no_profile_403(): void
    {
        Sanctum::actingAs(User::factory()->manager()->create());

        $this->getJson('/api/v1/me/team-leave-requests')
            ->assertForbidden()
            ->assertJsonPath('message', 'No staff record is linked to this account.');
    }

    public function test_an_unknown_status_is_a_422(): void
    {
        $this->actingAsLinked(User::factory()->manager()->create());

        $this->getJson('/api/v1/me/team-leave-requests?status=draft')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_guests_are_refused(): void
    {
        $this->getJson('/api/v1/me/team-leave-requests')->assertUnauthorized();
    }
}
