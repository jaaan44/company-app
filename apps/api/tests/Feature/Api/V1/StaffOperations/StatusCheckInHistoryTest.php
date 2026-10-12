<?php

namespace Tests\Feature\Api\V1\StaffOperations;

use App\Models\Staff;
use App\Models\StaffCheckIn;
use App\Models\StaffOperationalStatus;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Status and check-in history for the mobile My status area (Phase 30B
 * Gate 1, docs/phases/V1_PHASE_30_DEFINITION.md §6.3, R-14): a unique `id`
 * tie-breaker on all four history lists, and `meta.company_day` on the two
 * self-service lists — additive only; items, paging keys, validation and
 * scoping are unchanged.
 */
class StatusCheckInHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Staff $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config(['scheduling.company_timezone' => 'Asia/Manila']);
        // 2026-10-14 08:00 Manila.
        $this->travelTo(Carbon::parse('2026-10-14 00:00:00', 'UTC'));

        $this->user = User::factory()->staff()->create();
        $this->staff = Staff::factory()->create(['user_id' => $this->user->id]);
    }

    /**
     * Five rows sharing one `created_at` second, inserted in id order.
     *
     * @return list<string> the expected order, newest (highest id) first
     */
    private function sameSecondStatuses(Staff $staff): array
    {
        $statuses = ['available', 'busy', 'in_meeting', 'in_field', 'off_duty'];
        foreach ($statuses as $status) {
            StaffOperationalStatus::factory()->create([
                'staff_id' => $staff->id,
                'status' => $status,
                'created_at' => '2026-10-13 23:00:00',
            ]);
        }

        return array_reverse($statuses);
    }

    /**
     * @return list<string> labels, newest (highest id) first
     */
    private function sameSecondCheckIns(Staff $staff): array
    {
        $labels = ['A', 'B', 'C', 'D', 'E'];
        foreach ($labels as $label) {
            StaffCheckIn::factory()->create([
                'staff_id' => $staff->id,
                'location_label' => $label,
                'created_at' => '2026-10-13 23:00:00',
            ]);
        }

        return array_reverse($labels);
    }

    /**
     * Every row of a list, paged two at a time, as the app pages it.
     *
     * @return list<mixed>
     */
    private function allPages(string $uri, string $key): array
    {
        $seen = [];
        $page = 1;
        do {
            $body = $this->getJson("{$uri}?per_page=2&page={$page}")->assertOk()->json();
            foreach ($body['data'] as $item) {
                $seen[] = $item[$key];
            }
        } while ($page++ < $body['meta']['last_page']);

        return $seen;
    }

    public function test_my_status_history_breaks_ties_by_newest_id_across_pages(): void
    {
        $expected = $this->sameSecondStatuses($this->staff);
        Sanctum::actingAs($this->user);

        $this->assertSame($expected, $this->allPages('/api/v1/me/status', 'status'));
    }

    public function test_my_check_ins_break_ties_by_newest_id_across_pages(): void
    {
        $expected = $this->sameSecondCheckIns($this->staff);
        Sanctum::actingAs($this->user);

        $this->assertSame($expected, $this->allPages('/api/v1/me/check-ins', 'location_label'));
    }

    public function test_a_colleagues_status_history_breaks_ties_by_newest_id(): void
    {
        $colleague = Staff::factory()->create();
        $expected = $this->sameSecondStatuses($colleague);
        Sanctum::actingAs($this->user);

        $this->assertSame($expected, $this->allPages("/api/v1/staff/{$colleague->public_id}/status", 'status'));
    }

    public function test_a_direct_reports_check_ins_break_ties_by_newest_id(): void
    {
        $managerUser = User::factory()->manager()->create();
        $manager = Staff::factory()->create(['user_id' => $managerUser->id]);
        $report = Staff::factory()->create(['manager_id' => $manager->id]);
        $expected = $this->sameSecondCheckIns($report);
        Sanctum::actingAs($managerUser);

        $this->assertSame($expected, $this->allPages("/api/v1/staff/{$report->public_id}/check-ins", 'location_label'));
    }

    public function test_all_four_lists_order_by_created_at_then_id_in_sql(): void
    {
        // SQLite happens to return equal-second rows newest-first anyway
        // (it walks the (staff_id, created_at) index backwards), so the
        // tests above can't fail here without the tie-breaker; MySQL gives
        // no such guarantee. Pin the ORDER BY itself.
        $managerUser = User::factory()->manager()->create();
        $manager = Staff::factory()->create(['user_id' => $managerUser->id]);
        $report = Staff::factory()->create(['manager_id' => $manager->id]);
        // The paginator skips the select (and its ORDER BY) when it counts
        // no rows, so give every list one.
        foreach ([$this->staff, $report] as $staff) {
            StaffOperationalStatus::factory()->create(['staff_id' => $staff->id]);
            StaffCheckIn::factory()->create(['staff_id' => $staff->id]);
        }

        $orderBys = function (User $as, string $uri): array {
            Sanctum::actingAs($as);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson($uri)->assertOk();
            DB::disableQueryLog();

            return array_values(array_filter(
                array_map(fn ($q) => preg_match('/order by (.+?)( limit|$)/i', $q['query'], $m) ? $m[1] : null, DB::getQueryLog()),
            ));
        };

        foreach ([
            [$this->user, '/api/v1/me/status'],
            [$this->user, '/api/v1/me/check-ins'],
            [$this->user, "/api/v1/staff/{$report->public_id}/status"],
            [$managerUser, "/api/v1/staff/{$report->public_id}/check-ins"],
        ] as [$as, $uri]) {
            $this->assertSame(['"created_at" desc, "id" desc'], $orderBys($as, $uri), $uri);
        }
    }

    public function test_newer_rows_still_come_first_regardless_of_id(): void
    {
        // A later created_at wins over a higher id: the tie-breaker only
        // orders equal timestamps.
        StaffOperationalStatus::factory()->create(['staff_id' => $this->staff->id, 'status' => 'busy', 'created_at' => '2026-10-13 23:00:05']);
        StaffOperationalStatus::factory()->create(['staff_id' => $this->staff->id, 'status' => 'available', 'created_at' => '2026-10-13 23:00:00']);
        Sanctum::actingAs($this->user);

        $this->assertSame(['busy', 'available'], array_column($this->getJson('/api/v1/me/status')->json('data'), 'status'));
    }

    public function test_both_self_service_lists_give_the_company_day_and_keep_the_paginator_meta(): void
    {
        $this->sameSecondStatuses($this->staff);
        $this->sameSecondCheckIns($this->staff);
        Sanctum::actingAs($this->user);

        foreach (['/api/v1/me/status', '/api/v1/me/check-ins'] as $uri) {
            $this->getJson("{$uri}?per_page=2")
                ->assertOk()
                ->assertJsonPath('meta.company_day', ['date' => '2026-10-14', 'timezone' => 'Asia/Manila', 'utc_offset' => '+08:00'])
                ->assertJsonPath('meta.current_page', 1)
                ->assertJsonPath('meta.last_page', 3)
                ->assertJsonPath('meta.per_page', 2)
                ->assertJsonPath('meta.total', 5)
                ->assertJsonStructure(['links' => ['first', 'last', 'prev', 'next']]);
        }
    }

    public function test_the_company_day_follows_manila_not_utc(): void
    {
        Sanctum::actingAs($this->user);

        // 07:00 Manila on 15 Oct = 23:00 UTC on 14 Oct.
        $this->travelTo(Carbon::parse('2026-10-14 23:00:00', 'UTC'));
        $this->getJson('/api/v1/me/status')->assertJsonPath('meta.company_day.date', '2026-10-15');
        $this->getJson('/api/v1/me/check-ins')->assertJsonPath('meta.company_day.date', '2026-10-15');

        // 23:30 Manila on 15 Oct = 15:30 UTC on 15 Oct.
        $this->travelTo(Carbon::parse('2026-10-15 15:30:00', 'UTC'));
        $this->getJson('/api/v1/me/status')->assertJsonPath('meta.company_day.date', '2026-10-15');
        $this->getJson('/api/v1/me/check-ins')->assertJsonPath('meta.company_day.date', '2026-10-15');
    }

    public function test_an_empty_history_still_gives_the_company_day(): void
    {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/me/status')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.company_day.date', '2026-10-14');
    }

    public function test_the_other_staff_lists_are_unchanged_apart_from_order(): void
    {
        $colleague = Staff::factory()->create();
        StaffOperationalStatus::factory()->create(['staff_id' => $colleague->id]);
        Sanctum::actingAs($this->user);

        $this->getJson("/api/v1/staff/{$colleague->public_id}/status")
            ->assertOk()
            ->assertJsonMissingPath('meta.company_day');
    }

    public function test_item_shapes_are_unchanged(): void
    {
        StaffOperationalStatus::factory()->create(['staff_id' => $this->staff->id, 'status' => 'in_field']);
        StaffCheckIn::factory()->create(['staff_id' => $this->staff->id]);
        Sanctum::actingAs($this->user);

        $status = $this->getJson('/api/v1/me/status')->json('data.0');
        $this->assertSame(['status', 'recorded_at'], array_keys($status));

        $checkIn = $this->getJson('/api/v1/me/check-ins')->json('data.0');
        $this->assertSame(
            ['public_id', 'latitude', 'longitude', 'accuracy_meters', 'location_label', 'note', 'status', 'checked_in_at'],
            array_keys($checkIn),
        );
    }

    public function test_no_linked_staff_record_is_still_a_403_on_every_self_service_call(): void
    {
        Sanctum::actingAs(User::factory()->staff()->create());

        foreach ([
            $this->getJson('/api/v1/me/status'),
            $this->getJson('/api/v1/me/check-ins'),
            $this->postJson('/api/v1/me/status', ['status' => 'busy']),
            $this->postJson('/api/v1/me/check-ins', ['latitude' => 14.5547, 'longitude' => 121.0244]),
        ] as $response) {
            $response->assertForbidden()->assertJsonPath('message', 'No staff record is linked to this account.');
        }
    }

    public function test_check_in_validation_is_unchanged(): void
    {
        Sanctum::actingAs($this->user);
        $valid = ['latitude' => 14.5547, 'longitude' => 121.0244];

        foreach ([
            [['latitude' => null], 'latitude'],
            [['longitude' => 180.0001], 'longitude'],
            [['accuracy_meters' => -1], 'accuracy_meters'],
            [['accuracy_meters' => 100001], 'accuracy_meters'],
            [['location_label' => str_repeat('a', 101)], 'location_label'],
            [['note' => str_repeat('a', 501)], 'note'],
            [['status' => 'on_leave'], 'status'],
        ] as [$override, $field]) {
            $this->postJson('/api/v1/me/check-ins', [...$valid, ...$override])->assertUnprocessable()->assertJsonValidationErrors($field);
        }

        $this->postJson('/api/v1/me/check-ins', [...$valid, 'accuracy_meters' => 100000, 'location_label' => str_repeat('a', 100), 'note' => str_repeat('a', 500)])
            ->assertCreated();
        $this->assertSame(1, StaffCheckIn::query()->count());
    }

    public function test_a_check_in_still_never_changes_my_status(): void
    {
        StaffOperationalStatus::factory()->create(['staff_id' => $this->staff->id, 'status' => 'available']);
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/me/check-ins', ['latitude' => 14.5547, 'longitude' => 121.0244, 'status' => 'in_field'])->assertCreated();

        $this->assertSame(['available'], array_column($this->getJson('/api/v1/me/status')->json('data'), 'status'));
    }

    public function test_a_manager_still_cannot_see_a_non_reports_check_ins(): void
    {
        $managerUser = User::factory()->manager()->create();
        Staff::factory()->create(['user_id' => $managerUser->id]);
        Sanctum::actingAs($managerUser);

        $this->getJson("/api/v1/staff/{$this->staff->public_id}/check-ins")->assertForbidden();
    }
}
