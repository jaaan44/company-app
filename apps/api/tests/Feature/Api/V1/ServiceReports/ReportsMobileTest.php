<?php

namespace Tests\Feature\Api\V1\ServiceReports;

use App\Models\Client;
use App\Models\IncidentReport;
use App\Models\ServiceReport;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 33 (R-2): a service report's date is limited by the company
 * today; both report lists end in an `id` tie-breaker and carry
 * `meta.company_day`.
 */
class ReportsMobileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['scheduling.company_timezone' => 'Asia/Manila']);
    }

    private function actingAsStaff(): Staff
    {
        $user = User::factory()->staff()->create();
        $staff = Staff::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        return $staff;
    }

    private function create(string $date): TestResponse
    {
        return $this->postJson('/api/v1/service-reports', [
            'client_id' => Client::factory()->create()->public_id,
            'service_date' => $date,
            'work_performed' => 'Replaced the filter.',
        ]);
    }

    public function test_the_company_today_is_allowed_early_in_the_morning(): void
    {
        // 00:30 Manila on 15 October is 16:30 UTC on 14 October.
        $this->travelTo(Carbon::parse('2026-10-14 16:30:00', 'UTC'));
        $this->actingAsStaff();

        $this->create('2026-10-15')->assertCreated()->assertJsonPath('data.service_date', '2026-10-15');
    }

    public function test_the_company_tomorrow_is_refused_late_in_the_evening(): void
    {
        // 23:30 Manila on 15 October is 15:30 UTC the same day.
        $this->travelTo(Carbon::parse('2026-10-15 15:30:00', 'UTC'));
        $this->actingAsStaff();

        $this->create('2026-10-16')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['service_date' => 'The service date cannot be later than today.']);
        $this->create('2026-10-15')->assertCreated();
    }

    public function test_an_update_uses_the_same_company_today(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 16:30:00', 'UTC'));
        $staff = $this->actingAsStaff();
        $report = ServiceReport::factory()->create(['creator_staff_id' => $staff->id, 'service_date' => '2026-10-10']);

        $this->patchJson("/api/v1/service-reports/{$report->public_id}", ['service_date' => '2026-10-15'])
            ->assertOk()
            ->assertJsonPath('data.service_date', '2026-10-15');
        $this->patchJson("/api/v1/service-reports/{$report->public_id}", ['service_date' => '2026-10-16'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['service_date' => 'The service date cannot be later than today.']);
    }

    public function test_service_reports_list_with_company_day_and_tie_breaker(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 23:00:00', 'UTC'));
        $staff = $this->actingAsStaff();
        $a = ServiceReport::factory()->create(['creator_staff_id' => $staff->id, 'service_date' => '2026-10-10']);
        $b = ServiceReport::factory()->create(['creator_staff_id' => $staff->id, 'service_date' => '2026-10-10']);

        DB::enableQueryLog();
        $this->getJson('/api/v1/service-reports')
            ->assertOk()
            ->assertJsonPath('meta.company_day', ['date' => '2026-10-15', 'timezone' => 'Asia/Manila', 'utc_offset' => '+08:00'])
            ->assertJsonPath('data.*.public_id', [$b->public_id, $a->public_id]);

        $this->assertTrue(collect(DB::getQueryLog())->contains(
            fn (array $q) => str_contains($q['query'], 'order by "service_date" desc, "created_at" desc, "id" desc'),
        ));
    }

    public function test_incident_reports_list_with_company_day_and_tie_breaker(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 23:00:00', 'UTC'));
        $staff = $this->actingAsStaff();
        $a = IncidentReport::factory()->create(['reporter_staff_id' => $staff->id, 'occurred_at' => '2026-10-10 01:00:00']);
        $b = IncidentReport::factory()->create(['reporter_staff_id' => $staff->id, 'occurred_at' => '2026-10-10 01:00:00']);

        DB::enableQueryLog();
        $this->getJson('/api/v1/incident-reports')
            ->assertOk()
            ->assertJsonPath('meta.company_day.date', '2026-10-15')
            ->assertJsonPath('data.*.public_id', [$b->public_id, $a->public_id]);

        $this->assertTrue(collect(DB::getQueryLog())->contains(
            fn (array $q) => str_contains($q['query'], 'order by "occurred_at" desc, "created_at" desc, "id" desc'),
        ));
    }
}
