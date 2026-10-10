<?php

namespace Tests\Feature\Api\V1\WorkLogs;

use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\Staff;
use App\Models\User;
use App\Models\WorkLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 29B Gate 1 — work logs and the company day
 * (docs/phases/V1_PHASE_29_DEFINITION.md §6.3):
 * - R-8/R-10: "no later than today" means today in the company timezone,
 *   for self-service and Administrator, create and update;
 * - R-11: `GET /me/work-logs` has a unique tie-breaker;
 * - R-12: `GET /me/work-logs` states the company day in `meta`.
 */
class WorkLogCompanyDayTest extends TestCase
{
    use RefreshDatabase;

    private const FUTURE_MESSAGE = 'The work date cannot be later than today.';

    protected function setUp(): void
    {
        parent::setUp();

        config(['scheduling.company_timezone' => 'Asia/Manila']);
    }

    /**
     * 00:30 on 2026-10-10 in Manila is still 2026-10-09 in UTC — the window
     * in which the UTC rule rejected the Manila "today".
     */
    private function atHalfPastMidnightManila(): void
    {
        $this->travelTo(Carbon::parse('2026-10-09 16:30:00', 'UTC'));
    }

    /**
     * @return array{0: User, 1: Staff, 2: Project}
     */
    private function memberWithProject(): array
    {
        $user = User::factory()->staff()->create();
        $staff = Staff::factory()->create(['user_id' => $user->id]);
        $project = Project::factory()->create();
        ProjectMembership::factory()->for($project, 'project')->for($staff, 'staff')->create();

        return [$user, $staff, $project];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Project $project, string $date): array
    {
        return [
            'project_id' => $project->public_id,
            'work_date' => $date,
            'duration_minutes' => 30,
            'description' => 'Plant room check.',
        ];
    }

    // --- R-8 / R-10: company "today" ------------------------------------------

    public function test_self_service_can_log_the_company_today_just_after_manila_midnight(): void
    {
        $this->atHalfPastMidnightManila();
        [$user, , $project] = $this->memberWithProject();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/me/work-logs', $this->payload($project, '2026-10-10'))
            ->assertCreated()
            ->assertJsonPath('data.work_date', '2026-10-10');
    }

    public function test_self_service_cannot_log_the_company_tomorrow(): void
    {
        $this->atHalfPastMidnightManila();
        [$user, , $project] = $this->memberWithProject();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/me/work-logs', $this->payload($project, '2026-10-11'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['work_date' => self::FUTURE_MESSAGE]);
    }

    public function test_late_evening_manila_still_allows_today_and_refuses_tomorrow(): void
    {
        // 23:30 Manila on 2026-10-10 = 15:30 UTC the same day.
        $this->travelTo(Carbon::parse('2026-10-10 15:30:00', 'UTC'));
        [$user, , $project] = $this->memberWithProject();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/me/work-logs', $this->payload($project, '2026-10-10'))->assertCreated();
        $this->postJson('/api/v1/me/work-logs', $this->payload($project, '2026-10-11'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('work_date');
    }

    public function test_self_service_update_uses_the_company_today(): void
    {
        $this->atHalfPastMidnightManila();
        [$user, $staff] = $this->memberWithProject();
        $workLog = WorkLog::factory()->create(['staff_id' => $staff->id, 'work_date' => '2026-10-08']);
        Sanctum::actingAs($user);

        $this->patchJson("/api/v1/me/work-logs/{$workLog->public_id}", ['work_date' => '2026-10-10'])
            ->assertOk()
            ->assertJsonPath('data.work_date', '2026-10-10');
        $this->patchJson("/api/v1/me/work-logs/{$workLog->public_id}", ['work_date' => '2026-10-11'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['work_date' => self::FUTURE_MESSAGE]);
    }

    public function test_administrator_create_and_update_use_the_company_today(): void
    {
        $this->atHalfPastMidnightManila();
        Sanctum::actingAs(User::factory()->administrator()->create());
        $staff = Staff::factory()->create();
        $project = Project::factory()->create();

        $created = $this->postJson('/api/v1/work-logs', [
            'staff_id' => $staff->public_id,
            ...$this->payload($project, '2026-10-10'),
        ])->assertCreated();
        $this->postJson('/api/v1/work-logs', [
            'staff_id' => $staff->public_id,
            ...$this->payload($project, '2026-10-11'),
        ])->assertUnprocessable()->assertJsonValidationErrors(['work_date' => self::FUTURE_MESSAGE]);

        $id = $created->json('data.public_id');
        $this->patchJson("/api/v1/work-logs/{$id}", ['work_date' => '2026-10-09'])->assertOk();
        $this->patchJson("/api/v1/work-logs/{$id}", ['work_date' => '2026-10-10'])->assertOk();
        $this->patchJson("/api/v1/work-logs/{$id}", ['work_date' => '2026-10-11'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['work_date' => self::FUTURE_MESSAGE]);
    }

    // --- R-12: company day in the list ------------------------------------------

    public function test_the_list_states_the_company_day_and_keeps_its_paginator_meta(): void
    {
        $this->atHalfPastMidnightManila();
        [$user, $staff] = $this->memberWithProject();
        WorkLog::factory()->create(['staff_id' => $staff->id]);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/me/work-logs?per_page=25')->assertOk();

        $response->assertJsonPath('meta.company_day', ['date' => '2026-10-10', 'timezone' => 'Asia/Manila'])
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 25)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonCount(1, 'data');
        $this->assertSame(['data', 'links', 'meta'], array_keys($response->json()));
        foreach (['current_page', 'from', 'last_page', 'links', 'path', 'per_page', 'to', 'total'] as $key) {
            $this->assertArrayHasKey($key, $response->json('meta'));
        }
    }

    public function test_the_company_day_turns_over_at_manila_midnight(): void
    {
        [$user] = $this->memberWithProject();
        Sanctum::actingAs($user);

        $this->travelTo(Carbon::parse('2026-10-09 15:59:00', 'UTC'));
        $this->getJson('/api/v1/me/work-logs')->assertOk()->assertJsonPath('meta.company_day.date', '2026-10-09');

        $this->travelTo(Carbon::parse('2026-10-09 16:00:00', 'UTC'));
        $this->getJson('/api/v1/me/work-logs')->assertOk()->assertJsonPath('meta.company_day.date', '2026-10-10');
    }

    public function test_a_user_without_a_staff_record_still_gets_403(): void
    {
        // R-13: the API is unchanged; the app maps this to its no-profile state.
        Sanctum::actingAs(User::factory()->staff()->create());

        $this->getJson('/api/v1/me/work-logs')
            ->assertForbidden()
            ->assertJson(['message' => 'No staff record is linked to this account.']);
    }

    // --- R-11: tie-breaker --------------------------------------------------------

    public function test_logs_with_the_same_date_and_creation_time_page_stably(): void
    {
        [$user, $staff] = $this->memberWithProject();
        $logs = WorkLog::factory()->count(5)->create([
            'staff_id' => $staff->id,
            'work_date' => '2026-10-08',
            'created_at' => '2026-10-08 09:00:00',
        ]);
        Sanctum::actingAs($user);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $seen = [];
        foreach ([1, 2, 3] as $page) {
            $seen = [
                ...$seen,
                ...array_column($this->getJson("/api/v1/me/work-logs?per_page=2&page={$page}")->assertOk()->json('data'), 'public_id'),
            ];
        }
        $queries = implode("\n", array_column(DB::getQueryLog(), 'query'));
        DB::disableQueryLog();

        // Newest id first among equals; each log exactly once.
        $this->assertSame($logs->sortByDesc('id')->pluck('public_id')->values()->all(), $seen);
        // SQLite happens to return rowid order for equal keys, so pin the
        // ORDER BY itself (MySQL makes no such promise).
        $this->assertStringContainsString('order by "work_date" desc, "created_at" desc, "id" desc', $queries);
    }
}
