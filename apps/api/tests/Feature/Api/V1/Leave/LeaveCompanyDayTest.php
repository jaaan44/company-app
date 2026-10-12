<?php

namespace Tests\Feature\Api\V1\Leave;

use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Staff;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 31 (R-2): the self-service leave endpoints judge "this year" and
 * "already started" by the company day (DEC-052), list with a unique
 * tie-breaker, and return `meta.company_day`.
 */
class LeaveCompanyDayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config(['scheduling.company_timezone' => 'Asia/Manila']);
    }

    private function actingAsLinkedStaff(): Staff
    {
        $user = User::factory()->staff()->create();
        $staff = Staff::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        return $staff;
    }

    // --- R-2a: balances default to the company year --------------------

    public function test_balances_default_to_the_company_year_on_new_years_morning(): void
    {
        // 07:00 Manila on 1 January 2027 is still 2026 in UTC.
        $this->travelTo(Carbon::parse('2026-12-31 23:00:00', 'UTC'));
        $this->actingAsLinkedStaff();
        LeaveType::factory()->create();

        $this->getJson('/api/v1/me/leave-balances')
            ->assertOk()
            ->assertJsonPath('data.0.year', 2027);
    }

    public function test_balances_default_to_the_company_year_late_on_new_years_eve(): void
    {
        // 23:30 Manila on 31 December 2026 is 15:30 UTC — the same year.
        $this->travelTo(Carbon::parse('2026-12-31 15:30:00', 'UTC'));
        $this->actingAsLinkedStaff();
        LeaveType::factory()->create();

        $this->getJson('/api/v1/me/leave-balances')
            ->assertOk()
            ->assertJsonPath('data.0.year', 2026);
    }

    public function test_an_explicit_year_still_wins(): void
    {
        $this->travelTo(Carbon::parse('2026-12-31 23:00:00', 'UTC'));
        $this->actingAsLinkedStaff();
        LeaveType::factory()->create();

        $this->getJson('/api/v1/me/leave-balances?year=2026')
            ->assertOk()
            ->assertJsonPath('data.0.year', 2026);
    }

    // --- R-2b: self-cancel of approved leave, by the company day --------

    public function test_approved_leave_cannot_be_self_cancelled_early_on_its_first_company_day(): void
    {
        // 00:30 Manila on 15 October is 16:30 UTC on 14 October.
        $this->travelTo(Carbon::parse('2026-10-14 16:30:00', 'UTC'));
        $staff = $this->actingAsLinkedStaff();
        $leave = LeaveRequest::factory()->approved()->create([
            'staff_id' => $staff->id,
            'start_date' => '2026-10-15',
            'end_date' => '2026-10-16',
            'total_days' => 2,
        ]);

        $this->postJson("/api/v1/me/leave-requests/{$leave->public_id}/cancel")
            ->assertStatus(409)
            ->assertJsonPath('message', 'This leave request has already started and can no longer be self-cancelled.');

        $this->assertSame('approved', $leave->fresh()?->status->value);
    }

    public function test_approved_leave_cannot_be_self_cancelled_late_on_its_first_company_day(): void
    {
        // 23:30 Manila on 15 October is 15:30 UTC on 15 October.
        $this->travelTo(Carbon::parse('2026-10-15 15:30:00', 'UTC'));
        $staff = $this->actingAsLinkedStaff();
        $leave = LeaveRequest::factory()->approved()->create([
            'staff_id' => $staff->id,
            'start_date' => '2026-10-15',
            'end_date' => '2026-10-16',
            'total_days' => 2,
        ]);

        $this->postJson("/api/v1/me/leave-requests/{$leave->public_id}/cancel")
            ->assertStatus(409);
    }

    public function test_approved_leave_can_be_self_cancelled_the_company_day_before_it_starts(): void
    {
        // 23:30 Manila on 14 October (15:30 UTC) — tomorrow's leave.
        $this->travelTo(Carbon::parse('2026-10-14 15:30:00', 'UTC'));
        $staff = $this->actingAsLinkedStaff();
        $leave = LeaveRequest::factory()->approved()->create([
            'staff_id' => $staff->id,
            'start_date' => '2026-10-15',
            'end_date' => '2026-10-16',
            'total_days' => 2,
        ]);

        $this->postJson("/api/v1/me/leave-requests/{$leave->public_id}/cancel", ['reason' => 'Plans changed.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_pending_leave_can_be_self_cancelled_after_it_starts(): void
    {
        $this->travelTo(Carbon::parse('2026-10-15 03:00:00', 'UTC'));
        $staff = $this->actingAsLinkedStaff();
        $leave = LeaveRequest::factory()->create([
            'staff_id' => $staff->id,
            'start_date' => '2026-10-14',
            'end_date' => '2026-10-16',
            'total_days' => 3,
        ]);

        $this->postJson("/api/v1/me/leave-requests/{$leave->public_id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }

    // --- R-2c/d: tie-breaker and company day on my list -----------------

    public function test_my_requests_carry_the_company_day_and_keep_the_paginator_meta(): void
    {
        // 07:00 Manila on 15 October.
        $this->travelTo(Carbon::parse('2026-10-14 23:00:00', 'UTC'));
        $this->actingAsLinkedStaff();

        $this->getJson('/api/v1/me/leave-requests')
            ->assertOk()
            ->assertJsonPath('meta.company_day', [
                'date' => '2026-10-15',
                'timezone' => 'Asia/Manila',
                'utc_offset' => '+08:00',
            ])
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.current_page', 1);
    }

    public function test_my_requests_starting_the_same_day_are_newest_first_across_pages(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 03:00:00', 'UTC'));
        $staff = $this->actingAsLinkedStaff();
        $type = LeaveType::factory()->create();
        $ids = collect(range(1, 3))->map(fn () => LeaveRequest::factory()->cancelled()->create([
            'staff_id' => $staff->id,
            'leave_type_id' => $type->id,
            'start_date' => '2026-11-02',
            'end_date' => '2026-11-02',
            'total_days' => 1,
        ])->public_id)->all();

        DB::enableQueryLog();
        $first = $this->getJson('/api/v1/me/leave-requests?per_page=2')->assertOk();
        $second = $this->getJson('/api/v1/me/leave-requests?per_page=2&page=2')->assertOk();

        $this->assertSame(
            array_reverse($ids),
            [...$first->json('data.*.public_id'), ...$second->json('data.*.public_id')],
        );
        // SQLite returns ties in insertion order anyway, so pin the SQL.
        $this->assertTrue(collect(DB::getQueryLog())->contains(
            fn (array $q) => str_contains($q['query'], 'order by "start_date" desc, "id" desc'),
        ));
    }

    public function test_the_supervisory_list_has_the_id_tie_breaker(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 03:00:00', 'UTC'));
        Sanctum::actingAs(User::factory()->administrator()->create());
        LeaveRequest::factory()->create();

        DB::enableQueryLog();
        $this->getJson('/api/v1/leave-requests')->assertOk();

        $this->assertTrue(collect(DB::getQueryLog())->contains(
            fn (array $q) => str_contains($q['query'], 'order by "start_date" desc, "id" desc'),
        ));
    }
}
