<?php

namespace Tests\Feature\Api\V1\Reports;

use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Staff;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Leave Requests report (Phase 20 — Reports, DEC-043). No `can:`
 * route middleware — visibility mirrors LeaveRequestController's own
 * supervisory surface (App\Services\Reporting\LeaveRequestVisibility),
 * plus a plain-Staff own-records fallback.
 */
class LeaveRequestReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function actingAsAdministrator(): User
    {
        $user = User::factory()->administrator()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/v1/reports/leave-requests')->assertUnauthorized();
    }

    public function test_administrator_sees_every_leave_request(): void
    {
        $this->actingAsAdministrator();
        LeaveRequest::factory()->count(2)->create();

        $this->getJson('/api/v1/reports/leave-requests')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_an_ordinary_staff_member_sees_only_their_own_leave_requests(): void
    {
        $user = User::factory()->staff()->create();
        $staff = Staff::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        $mine = LeaveRequest::factory()->create(['staff_id' => $staff->id]);
        LeaveRequest::factory()->create();

        $this->getJson('/api/v1/reports/leave-requests')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJson(['data' => [['public_id' => $mine->public_id]]]);
    }

    public function test_a_manager_sees_only_their_direct_reports_leave_requests(): void
    {
        $managerUser = User::factory()->manager()->create();
        $manager = Staff::factory()->create(['user_id' => $managerUser->id]);
        Sanctum::actingAs($managerUser);

        $report = Staff::factory()->create(['manager_id' => $manager->id]);
        $reportRequest = LeaveRequest::factory()->create(['staff_id' => $report->id]);
        LeaveRequest::factory()->create();

        $this->getJson('/api/v1/reports/leave-requests')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJson(['data' => [['public_id' => $reportRequest->public_id]]]);
    }

    public function test_filters_by_staff_leave_type_status_and_date_range(): void
    {
        $this->actingAsAdministrator();

        $staff = Staff::factory()->create();
        $type = LeaveType::factory()->create();

        $byStaff = LeaveRequest::factory()->create(['staff_id' => $staff->id, 'start_date' => '2026-05-01', 'end_date' => '2026-05-02']);
        $byType = LeaveRequest::factory()->for($type, 'leaveType')->create(['start_date' => '2026-05-01', 'end_date' => '2026-05-02']);
        $approved = LeaveRequest::factory()->approved()->create(['start_date' => '2026-05-01', 'end_date' => '2026-05-02']);
        LeaveRequest::factory()->create(['start_date' => '2020-01-01', 'end_date' => '2020-01-02']); // outside range

        $this->getJson("/api/v1/reports/leave-requests?staff={$staff->public_id}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJson(['data' => [['public_id' => $byStaff->public_id]]]);

        $this->getJson("/api/v1/reports/leave-requests?type={$type->public_id}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJson(['data' => [['public_id' => $byType->public_id]]]);

        $this->getJson('/api/v1/reports/leave-requests?status=approved')
            ->assertOk()->assertJsonCount(1, 'data')->assertJson(['data' => [['public_id' => $approved->public_id]]]);

        $this->getJson('/api/v1/reports/leave-requests?from=2026-01-01&to=2026-12-31')
            ->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_response_is_paginated_and_never_exposes_internal_ids(): void
    {
        $this->actingAsAdministrator();
        LeaveRequest::factory()->create();

        $this->getJson('/api/v1/reports/leave-requests')
            ->assertOk()
            ->assertJsonStructure(['data', 'links', 'meta'])
            ->assertJsonMissingPath('data.0.id');
    }

    public function test_csv_export_matches_json_filters_and_authorization_with_no_internal_ids(): void
    {
        $user = User::factory()->staff()->create();
        $staff = Staff::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        $mine = LeaveRequest::factory()->create(['staff_id' => $staff->id, 'reason' => 'Family trip']);
        LeaveRequest::factory()->create(); // someone else's

        $csv = $this->get('/api/v1/reports/leave-requests/export')->assertOk()->streamedContent();

        $headerLine = strstr(ltrim($csv, "\xEF\xBB\xBF"), "\n", true);
        $this->assertSame('Public ID', str_getcsv($headerLine)[0]);
        $this->assertSame('Staff Public ID', str_getcsv($headerLine)[1]);
        $this->assertStringContainsString($mine->public_id, $csv);
        $this->assertStringContainsString('Family trip', $csv);
        $this->assertSame(2, substr_count($csv, "\n"));
    }

    public function test_csv_export_neutralizes_formula_injection(): void
    {
        $this->actingAsAdministrator();
        LeaveRequest::factory()->create(['reason' => '@SUM(1,2)']);

        $csv = $this->get('/api/v1/reports/leave-requests/export')->streamedContent();

        $this->assertStringContainsString("'@SUM(1,2)", $csv);
    }
}
