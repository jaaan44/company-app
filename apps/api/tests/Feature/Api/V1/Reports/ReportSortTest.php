<?php

namespace Tests\Feature\Api\V1\Reports;

use App\Models\Staff;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkLog;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `?sort=` on the reports (Phase 37B, DEC-068): `field` ascending,
 * `-field` descending, only the fields a report names; without it, the
 * report's own order. The CSV export follows the same order.
 */
class ReportSortTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Sanctum::actingAs(User::factory()->administrator()->create());
    }

    public function test_a_report_sorts_by_an_allowed_field_either_way(): void
    {
        Task::factory()->create(['title' => 'Bravo', 'due_date' => '2026-10-01']);
        Task::factory()->create(['title' => 'Alpha', 'due_date' => '2026-10-03']);
        Task::factory()->create(['title' => 'Charlie', 'due_date' => '2026-10-02']);

        $titles = fn (string $query) => array_column($this->getJson('/api/v1/reports/tasks'.$query)->assertOk()->json('data'), 'title');

        $this->assertSame(['Bravo', 'Charlie', 'Alpha'], $titles('')); // its own order: due date
        $this->assertSame(['Alpha', 'Bravo', 'Charlie'], $titles('?sort=title'));
        $this->assertSame(['Charlie', 'Bravo', 'Alpha'], $titles('?sort=-title'));
        $this->assertSame(['Alpha', 'Charlie', 'Bravo'], $titles('?sort=-due_date'));
    }

    public function test_a_field_the_report_does_not_name_is_refused(): void
    {
        $this->getJson('/api/v1/reports/tasks?sort=status')->assertUnprocessable()->assertJsonValidationErrors('sort');
        $this->getJson('/api/v1/reports/staff?sort=-password')->assertUnprocessable()->assertJsonValidationErrors('sort');
    }

    public function test_names_sort_by_last_then_first_name_and_numbers_numerically(): void
    {
        Staff::factory()->create(['first_name' => 'Ana', 'last_name' => 'Zulu']);
        Staff::factory()->create(['first_name' => 'Ben', 'last_name' => 'Adams']);
        Staff::factory()->create(['first_name' => 'Al', 'last_name' => 'Adams']);

        $last = array_map(fn (array $row) => $row['first_name'].' '.$row['last_name'], $this->getJson('/api/v1/reports/staff?sort=-name')->assertOk()->json('data'));
        $this->assertSame(['Ana Zulu', 'Ben Adams', 'Al Adams'], $last);

        WorkLog::factory()->create(['duration_minutes' => 90]);
        WorkLog::factory()->create(['duration_minutes' => 600]);
        WorkLog::factory()->create(['duration_minutes' => 15]);
        $minutes = array_column($this->getJson('/api/v1/reports/work-logs?sort=duration_minutes')->assertOk()->json('data'), 'duration_minutes');
        $this->assertSame([15, 90, 600], $minutes);
    }

    public function test_the_export_follows_the_sort(): void
    {
        Task::factory()->create(['title' => 'Bravo']);
        Task::factory()->create(['title' => 'Alpha']);

        $csv = $this->get('/api/v1/reports/tasks/export?sort=-title')->assertOk()->streamedContent();
        $this->assertLessThan(strpos($csv, 'Alpha'), strpos($csv, 'Bravo'));
    }
}
