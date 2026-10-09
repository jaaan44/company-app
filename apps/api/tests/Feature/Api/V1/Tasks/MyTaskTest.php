<?php

namespace Tests\Feature\Api\V1\Tasks;

use App\Models\Project;
use App\Models\Staff;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * My tasks (Phase 29A, R-2/R-3) — `GET /api/v1/me/tasks`: authentication,
 * the response contract, self-scope for every role, open/closed states,
 * ordering and stable pagination, company-timezone flags, and parity with
 * `/me/home`. See docs/phases/V1_PHASE_29_DEFINITION.md §5.1.
 */
class MyTaskTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config(['scheduling.company_timezone' => 'UTC']);
        $this->travelTo(Carbon::parse('2026-09-24 08:00:00', 'UTC'));
    }

    /**
     * @return array{0: User, 1: Staff}
     */
    private function employee(string $role = 'staff'): array
    {
        $user = User::factory()->{$role}()->create();
        $staff = Staff::factory()->create(['user_id' => $user->id]);

        return [$user, $staff];
    }

    /**
     * @param  array<mixed>  $data
     * @return list<string>
     */
    private function allKeys(array $data): array
    {
        $keys = [];
        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $keys[] = $key;
            }
            if (is_array($value)) {
                $keys = [...$keys, ...$this->allKeys($value)];
            }
        }

        return $keys;
    }

    /**
     * @return list<string>
     */
    private function titles(string $uri): array
    {
        return array_column($this->getJson($uri)->assertOk()->json('data.tasks'), 'title');
    }

    // --- Authentication -----------------------------------------------------

    public function test_my_tasks_requires_authentication(): void
    {
        $this->getJson('/api/v1/me/tasks')->assertUnauthorized();
    }

    public function test_an_inactive_account_gets_403(): void
    {
        $user = User::factory()->staff()->suspended()->create();
        Staff::factory()->create(['user_id' => $user->id]);
        $token = $user->createToken('mobile')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/me/tasks')
            ->assertForbidden()
            ->assertJson(['message' => 'This account is not currently active.']);
    }

    // --- Contract -----------------------------------------------------------

    public function test_the_response_has_the_approved_shape(): void
    {
        [$user, $staff] = $this->employee();
        $project = Project::factory()->create(['name' => 'Fit-out']);
        Task::factory()->create([
            'assignee_staff_id' => $staff->id,
            'project_id' => $project->id,
            'title' => 'Mine',
            'due_date' => '2026-09-24',
        ]);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/me/tasks')->assertOk();

        $this->assertSame(['company_day', 'tasks'], array_keys($response->json('data')));
        $this->assertSame(['date' => '2026-09-24', 'timezone' => 'UTC'], $response->json('data.company_day'));
        $this->assertSame(
            ['current_page' => 1, 'last_page' => 1, 'per_page' => 25, 'total' => 1],
            $response->json('meta'),
        );
        $this->assertSame([
            'public_id', 'title', 'description', 'status', 'priority', 'due_date',
            'completed_at', 'project', 'assignee', 'created_by', 'created_at',
            'updated_at', 'is_overdue', 'is_due_today',
        ], array_keys($response->json('data.tasks.0')));
        $response->assertJsonPath('data.tasks.0.project.name', 'Fit-out')
            ->assertJsonPath('data.tasks.0.assignee.public_id', $staff->public_id);
    }

    public function test_no_internal_numeric_id_appears_anywhere(): void
    {
        [$user, $staff] = $this->employee();
        Task::factory()->create([
            'assignee_staff_id' => $staff->id,
            'project_id' => Project::factory()->create()->id,
        ]);
        Sanctum::actingAs($user);

        $keys = $this->allKeys($this->getJson('/api/v1/me/tasks')->assertOk()->json());

        foreach (['id', 'user_id', 'staff_id', 'project_id', 'assignee_staff_id', 'created_by_user_id'] as $forbidden) {
            $this->assertNotContains($forbidden, $keys);
        }
    }

    public function test_a_user_without_a_staff_record_gets_200_with_null_tasks(): void
    {
        Sanctum::actingAs(User::factory()->staff()->create());

        $this->getJson('/api/v1/me/tasks')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'company_day' => ['date' => '2026-09-24', 'timezone' => 'UTC'],
                    'tasks' => null,
                ],
                'meta' => null,
            ]);
    }

    public function test_an_employee_with_no_tasks_gets_an_empty_list(): void
    {
        [$user] = $this->employee();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/me/tasks')
            ->assertOk()
            ->assertJsonPath('data.tasks', [])
            ->assertJsonPath('meta.total', 0);
    }

    // --- Self-scope ---------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function roles(): array
    {
        return [
            'staff' => ['staff'],
            'manager' => ['manager'],
            'administrator' => ['administrator'],
        ];
    }

    #[DataProvider('roles')]
    public function test_every_role_sees_only_tasks_assigned_to_them(string $role): void
    {
        [$user, $staff] = $this->employee($role);
        $report = Staff::factory()->create(['manager_id' => $staff->id]);
        Task::factory()->create(['assignee_staff_id' => $staff->id, 'title' => 'Mine']);
        Task::factory()->create(['assignee_staff_id' => $report->id, 'title' => 'Report']);
        Task::factory()->create(['assignee_staff_id' => Staff::factory()->create()->id, 'title' => 'Other']);
        Task::factory()->create(['title' => 'Unassigned']);
        // Created by me but assigned elsewhere — still not mine (R-2).
        Task::factory()->create([
            'assignee_staff_id' => $report->id,
            'created_by_user_id' => $user->id,
            'title' => 'Created by me',
        ]);
        Sanctum::actingAs($user);

        $this->assertSame(['Mine'], $this->titles('/api/v1/me/tasks'));
    }

    public function test_request_parameters_cannot_change_the_subject(): void
    {
        [$user] = $this->employee();
        $other = Staff::factory()->create();
        Task::factory()->create(['assignee_staff_id' => $other->id]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/me/tasks?assignee='.$other->public_id.'&staff='.$other->public_id)
            ->assertOk()
            ->assertJsonPath('data.tasks', []);
    }

    // --- States -------------------------------------------------------------

    public function test_open_is_the_default_and_excludes_completed_and_cancelled(): void
    {
        [$user, $staff] = $this->employee();
        $mine = ['assignee_staff_id' => $staff->id];
        Task::factory()->create([...$mine, 'title' => 'Todo']);
        Task::factory()->inProgress()->create([...$mine, 'title' => 'In progress']);
        Task::factory()->blocked()->create([...$mine, 'title' => 'Blocked']);
        Task::factory()->completed()->create([...$mine, 'title' => 'Completed']);
        Task::factory()->cancelled()->create([...$mine, 'title' => 'Cancelled']);
        Sanctum::actingAs($user);

        $open = $this->titles('/api/v1/me/tasks');
        sort($open);
        $this->assertSame(['Blocked', 'In progress', 'Todo'], $open);
        $explicit = $this->titles('/api/v1/me/tasks?state=open');
        sort($explicit);
        $this->assertSame($open, $explicit);

        $closed = $this->titles('/api/v1/me/tasks?state=closed');
        sort($closed);
        $this->assertSame(['Cancelled', 'Completed'], $closed);
    }

    public function test_an_unknown_state_is_rejected(): void
    {
        [$user] = $this->employee();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/me/tasks?state=all')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('state');
    }

    // --- Ordering & pagination ------------------------------------------------

    public function test_open_tasks_are_ordered_by_due_date_with_undated_last(): void
    {
        [$user, $staff] = $this->employee();
        $mine = ['assignee_staff_id' => $staff->id];
        Task::factory()->create([...$mine, 'title' => 'Undated A']);
        Task::factory()->create([...$mine, 'title' => 'Later', 'due_date' => '2026-10-01']);
        Task::factory()->create([...$mine, 'title' => 'Overdue', 'due_date' => '2026-09-01']);
        Task::factory()->create([...$mine, 'title' => 'Undated B']);
        Task::factory()->create([...$mine, 'title' => 'Today', 'due_date' => '2026-09-24']);
        Sanctum::actingAs($user);

        $this->assertSame(
            ['Overdue', 'Today', 'Later', 'Undated A', 'Undated B'],
            $this->titles('/api/v1/me/tasks'),
        );
    }

    public function test_closed_tasks_are_ordered_most_recently_finished_first(): void
    {
        [$user, $staff] = $this->employee();
        $mine = ['assignee_staff_id' => $staff->id];
        Task::factory()->completed()->create([...$mine, 'title' => 'Done long ago', 'completed_at' => '2026-08-01 10:00:00']);
        Task::factory()->completed()->create([...$mine, 'title' => 'Done yesterday', 'completed_at' => '2026-09-23 10:00:00']);
        Task::factory()->cancelled()->create([...$mine, 'title' => 'Cancelled', 'updated_at' => '2026-09-24 07:00:00']);
        Sanctum::actingAs($user);

        // Cancelled tasks have no completed_at and come after every completed one.
        $this->assertSame(
            ['Done yesterday', 'Done long ago', 'Cancelled'],
            $this->titles('/api/v1/me/tasks?state=closed'),
        );
    }

    public function test_pagination_is_stable_across_identical_sort_keys(): void
    {
        [$user, $staff] = $this->employee();
        // Every task shares the same due date — only the id tie-breaker orders them.
        $created = Task::factory()->count(7)->create([
            'assignee_staff_id' => $staff->id,
            'due_date' => '2026-09-30',
        ]);
        Sanctum::actingAs($user);

        $seen = [];
        foreach ([1, 2, 3] as $page) {
            $response = $this->getJson("/api/v1/me/tasks?per_page=3&page={$page}")->assertOk();
            $response->assertJsonPath('meta.last_page', 3)->assertJsonPath('meta.total', 7);
            $seen = [...$seen, ...array_column($response->json('data.tasks'), 'public_id')];
        }

        $this->assertSame($created->sortBy('id')->pluck('public_id')->all(), $seen);
    }

    public function test_both_orders_end_in_the_unique_id_tie_breaker(): void
    {
        // SQLite happens to return rowid order for equal keys, so the
        // stability test above can't catch a dropped tie-breaker on its
        // own; MySQL makes no such promise. Pin the ORDER BY itself.
        [$user, $staff] = $this->employee();
        Task::factory()->create(['assignee_staff_id' => $staff->id]);
        Task::factory()->completed()->create(['assignee_staff_id' => $staff->id]);
        Sanctum::actingAs($user);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/v1/me/tasks')->assertOk();
        $this->getJson('/api/v1/me/tasks?state=closed')->assertOk();
        $queries = implode("\n", array_column(DB::getQueryLog(), 'query'));
        DB::disableQueryLog();

        $this->assertStringContainsString('order by due_date IS NULL, "due_date" asc, "id" asc', $queries);
        $this->assertStringContainsString(
            'order by completed_at IS NULL, "completed_at" desc, "updated_at" desc, "id" asc',
            $queries,
        );
    }

    public function test_per_page_defaults_to_25_and_is_capped_at_50(): void
    {
        [$user, $staff] = $this->employee();
        Task::factory()->count(26)->create(['assignee_staff_id' => $staff->id]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/me/tasks')
            ->assertOk()
            ->assertJsonCount(25, 'data.tasks')
            ->assertJsonPath('meta.per_page', 25)
            ->assertJsonPath('meta.last_page', 2);

        $this->getJson('/api/v1/me/tasks?per_page=50')->assertOk()->assertJsonCount(26, 'data.tasks');
        $this->getJson('/api/v1/me/tasks?per_page=51')->assertUnprocessable()->assertJsonValidationErrors('per_page');
        $this->getJson('/api/v1/me/tasks?per_page=0')->assertUnprocessable()->assertJsonValidationErrors('per_page');
    }

    // --- Flags ----------------------------------------------------------------

    public function test_overdue_and_due_today_flags(): void
    {
        [$user, $staff] = $this->employee();
        $mine = ['assignee_staff_id' => $staff->id];
        Task::factory()->create([...$mine, 'title' => 'Overdue', 'due_date' => '2026-09-23']);
        Task::factory()->create([...$mine, 'title' => 'Today', 'due_date' => '2026-09-24']);
        Task::factory()->create([...$mine, 'title' => 'Future', 'due_date' => '2026-09-25']);
        Task::factory()->create([...$mine, 'title' => 'Undated']);
        Task::factory()->completed()->create([...$mine, 'title' => 'Done late', 'due_date' => '2026-09-01']);
        Task::factory()->cancelled()->create([...$mine, 'title' => 'Cancelled today', 'due_date' => '2026-09-24']);
        Sanctum::actingAs($user);

        $flags = fn (string $uri) => collect($this->getJson($uri)->assertOk()->json('data.tasks'))
            ->mapWithKeys(fn (array $t) => [$t['title'] => [$t['is_overdue'], $t['is_due_today']]])
            ->all();

        $this->assertSame([
            'Overdue' => [true, false],
            'Today' => [false, true],
            'Future' => [false, false],
            'Undated' => [false, false],
        ], $flags('/api/v1/me/tasks'));

        // A closed task is never overdue or due today.
        $closed = $flags('/api/v1/me/tasks?state=closed');
        ksort($closed);
        $this->assertSame([
            'Cancelled today' => [false, false],
            'Done late' => [false, false],
        ], $closed);
    }

    public function test_flags_follow_the_company_day_around_manila_midnight(): void
    {
        config(['scheduling.company_timezone' => 'Asia/Manila']);
        [$user, $staff] = $this->employee();
        Task::factory()->create(['assignee_staff_id' => $staff->id, 'due_date' => '2026-09-24']);
        Sanctum::actingAs($user);

        // 15:59 UTC = 23:59 Manila on the 24th: still due today.
        $this->travelTo(Carbon::parse('2026-09-24 15:59:00', 'UTC'));
        $this->getJson('/api/v1/me/tasks')
            ->assertOk()
            ->assertJsonPath('data.company_day', ['date' => '2026-09-24', 'timezone' => 'Asia/Manila'])
            ->assertJsonPath('data.tasks.0.is_due_today', true)
            ->assertJsonPath('data.tasks.0.is_overdue', false);

        // 16:00 UTC = 00:00 Manila on the 25th (still the 24th in UTC): overdue.
        $this->travelTo(Carbon::parse('2026-09-24 16:00:00', 'UTC'));
        $this->getJson('/api/v1/me/tasks')
            ->assertOk()
            ->assertJsonPath('data.company_day.date', '2026-09-25')
            ->assertJsonPath('data.tasks.0.is_due_today', false)
            ->assertJsonPath('data.tasks.0.is_overdue', true);
    }

    public function test_counts_match_my_home(): void
    {
        [$user, $staff] = $this->employee();
        $mine = ['assignee_staff_id' => $staff->id];
        Task::factory()->count(2)->create([...$mine, 'due_date' => '2026-09-01']);
        Task::factory()->create([...$mine, 'due_date' => '2026-09-24']);
        Task::factory()->blocked()->create($mine);
        Task::factory()->completed()->create([...$mine, 'due_date' => '2026-09-01']);
        Task::factory()->cancelled()->create($mine);
        Task::factory()->create(['due_date' => '2026-09-01']);
        Sanctum::actingAs($user);

        $home = $this->getJson('/api/v1/me/home')->assertOk()->json('data.tasks');
        $open = $this->getJson('/api/v1/me/tasks?per_page=50')->assertOk();

        $this->assertSame(4, $home['open_count']);
        $this->assertSame($home['open_count'], $open->json('meta.total'));
        $this->assertSame($home['overdue_count'], collect($open->json('data.tasks'))->where('is_overdue', true)->count());
        $this->assertSame($home['due_today_count'], collect($open->json('data.tasks'))->where('is_due_today', true)->count());
    }

    // --- Performance ------------------------------------------------------------

    public function test_the_query_count_does_not_grow_with_the_number_of_tasks(): void
    {
        [$user, $staff] = $this->employee();
        $project = Project::factory()->create();
        $count = function () use ($user): int {
            // A fresh instance each time, so a cached `staff` relation
            // from an earlier request can't hide or add a query.
            Sanctum::actingAs($user->fresh());
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/v1/me/tasks')->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $creator = function (): int {
            $creator = User::factory()->manager()->create();
            Staff::factory()->create(['user_id' => $creator->id]);

            return $creator->id;
        };

        Task::factory()->create([
            'assignee_staff_id' => $staff->id,
            'project_id' => $project->id,
            'created_by_user_id' => $creator(),
        ]);
        $one = $count();

        foreach (range(1, 10) as $_) {
            Task::factory()->create([
                'assignee_staff_id' => $staff->id,
                'project_id' => Project::factory()->create()->id,
                'created_by_user_id' => $creator(),
            ]);
        }

        $this->assertSame($one, $count());
    }
}
