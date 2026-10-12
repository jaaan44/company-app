<?php

namespace Tests\Feature\Api\V1\Scheduling;

use App\Models\LeaveRequest;
use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Models\ScheduleEntry;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `GET /schedule` day boundaries (Phase 30A Gate 1, R-5; the Phase 27 R-7
 * item): `from`/`to` are company days. With Asia/Manila, an item between
 * 00:00 and 07:59 Manila used to fall on the previous (UTC) day.
 */
class ScheduleCompanyDayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config(['scheduling.company_timezone' => 'Asia/Manila']);
        $this->travelTo(Carbon::parse('2026-10-14 00:00:00', 'UTC'));
        Sanctum::actingAs(User::factory()->administrator()->create());
    }

    /**
     * @return list<string>
     */
    private function titles(string $from, string $to): array
    {
        return array_column(
            $this->getJson("/api/v1/schedule?from={$from}&to={$to}&per_page=100")->assertOk()->json('data'),
            'title',
        );
    }

    public function test_an_early_morning_manila_entry_is_on_its_manila_day(): void
    {
        // 07:00–07:30 Manila on 14 Oct = 23:00–23:30 UTC on 13 Oct.
        ScheduleEntry::factory()->create(['title' => 'Early', 'starts_at' => '2026-10-13 23:00:00', 'ends_at' => '2026-10-13 23:30:00']);

        $this->assertSame(['Early'], $this->titles('2026-10-14', '2026-10-14'));
        $this->assertSame([], $this->titles('2026-10-13', '2026-10-13'));
    }

    public function test_a_late_evening_manila_entry_stays_on_its_manila_day(): void
    {
        // 23:30–23:59 Manila on 14 Oct = 15:30–15:59 UTC on 14 Oct.
        ScheduleEntry::factory()->create(['title' => 'Late', 'starts_at' => '2026-10-14 15:30:00', 'ends_at' => '2026-10-14 15:59:00']);

        $this->assertSame(['Late'], $this->titles('2026-10-14', '2026-10-14'));
        $this->assertSame([], $this->titles('2026-10-15', '2026-10-15'));
    }

    public function test_date_only_sources_use_their_company_dates(): void
    {
        $project = Project::factory()->create();
        Task::factory()->create(['title' => 'Task', 'due_date' => '2026-10-14']);
        ProjectMilestone::factory()->create(['title' => 'Milestone', 'project_id' => $project->id, 'due_date' => '2026-10-14']);
        LeaveRequest::factory()->approved()->create(['start_date' => '2026-10-14', 'end_date' => '2026-10-14']);

        $this->assertEqualsCanonicalizing(['Task', 'Milestone', 'Leave'], $this->titles('2026-10-14', '2026-10-14'));
        $this->assertSame([], $this->titles('2026-10-13', '2026-10-13'));
        $this->assertSame([], $this->titles('2026-10-15', '2026-10-15'));
    }

    public function test_a_datetime_from_still_means_its_date(): void
    {
        // `from`/`to` keep accepting any date string, as before.
        ScheduleEntry::factory()->create(['title' => 'Early', 'starts_at' => '2026-10-13 23:00:00', 'ends_at' => '2026-10-13 23:30:00']);

        $this->assertSame(['Early'], $this->titles('2026-10-14T10:00:00', '2026-10-14T10:00:00'));
    }
}
