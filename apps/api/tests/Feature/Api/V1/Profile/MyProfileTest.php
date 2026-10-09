<?php

namespace Tests\Feature\Api\V1\Profile;

use App\Models\Department;
use App\Models\Position;
use App\Models\Staff;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * My profile (Phase 28, R-2) — `GET /api/v1/me/profile`: authentication,
 * the response contract, self-scoping, no-profile behavior, and parity
 * with the Phase 7 `GET /api/v1/staff/{public_id}` shape. See
 * docs/phases/V1_PHASE_28_DEFINITION.md §6.1.
 */
class MyProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * An employee placed in the organization with a manager, so every
     * StaffResource relation is populated.
     *
     * @return array{0: User, 1: Staff}
     */
    private function employee(string $role = 'staff'): array
    {
        $department = Department::factory()->create();
        $team = Team::factory()->create(['department_id' => $department->id]);
        $position = Position::factory()->create();
        $manager = Staff::factory()->create();

        $user = User::factory()->{$role}()->create();
        $staff = Staff::factory()->create([
            'user_id' => $user->id,
            'department_id' => $department->id,
            'team_id' => $team->id,
            'position_id' => $position->id,
            'manager_id' => $manager->id,
            'preferred_name' => 'Sam',
        ]);

        return [$user, $staff];
    }

    /**
     * Every key at every depth.
     *
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

    // --- Authentication ---------------------------------------------------

    public function test_profile_requires_authentication(): void
    {
        $this->getJson('/api/v1/me/profile')->assertUnauthorized();
    }

    public function test_an_invalid_token_is_rejected(): void
    {
        $this->withHeader('Authorization', 'Bearer 1|not-a-real-token')
            ->getJson('/api/v1/me/profile')
            ->assertUnauthorized();
    }

    public function test_an_inactive_account_gets_403(): void
    {
        $user = User::factory()->staff()->suspended()->create();
        Staff::factory()->create(['user_id' => $user->id]);
        $token = $user->createToken('mobile')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/me/profile')
            ->assertForbidden()
            ->assertJson(['message' => 'This account is not currently active.']);
    }

    // --- Contract -----------------------------------------------------------

    public function test_a_staff_user_gets_their_own_account_and_staff_record(): void
    {
        [$user, $staff] = $this->employee();
        $staff->load(['department', 'team', 'position', 'manager']);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/me/profile')
            ->assertOk()
            ->assertExactJsonStructure(['data' => ['user', 'staff']])
            ->assertJson(['data' => [
                'user' => [
                    'public_id' => $user->public_id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => 'staff',
                ],
                'staff' => [
                    'public_id' => $staff->public_id,
                    'employee_number' => $staff->employee_number,
                    'display_name' => 'Sam',
                    'company_email' => $staff->company_email,
                    'company_phone' => $staff->company_phone,
                    'status' => 'active',
                    'hire_date' => $staff->hire_date?->toDateString(),
                    'department' => ['public_id' => $staff->department->public_id, 'name' => $staff->department->name],
                    'team' => ['public_id' => $staff->team->public_id, 'name' => $staff->team->name],
                    'position' => ['public_id' => $staff->position->public_id, 'title' => $staff->position->title],
                    'manager' => ['public_id' => $staff->manager->public_id, 'display_name' => $staff->manager->displayName()],
                    'has_user_account' => true,
                ],
            ]]);
    }

    public function test_the_user_block_has_exactly_the_approved_fields(): void
    {
        [$user] = $this->employee();
        Sanctum::actingAs($user);

        $this->assertSame(
            ['public_id', 'name', 'email', 'role'],
            array_keys($this->getJson('/api/v1/me/profile')->json('data.user')),
        );
    }

    public function test_the_staff_block_is_identical_to_get_staff_for_the_same_requester(): void
    {
        foreach (['staff', 'manager', 'administrator'] as $role) {
            [$user, $staff] = $this->employee($role);
            Sanctum::actingAs($user);

            $this->assertSame(
                $this->getJson("/api/v1/staff/{$staff->public_id}")->assertOk()->json('data'),
                $this->getJson('/api/v1/me/profile')->assertOk()->json('data.staff'),
                "Shape mismatch for role {$role}",
            );
        }
    }

    public function test_the_manage_only_user_block_is_present_only_for_an_administrator(): void
    {
        [$staffUser] = $this->employee('staff');
        Sanctum::actingAs($staffUser);
        $this->getJson('/api/v1/me/profile')->assertOk()->assertJsonMissingPath('data.staff.user');

        [$manager] = $this->employee('manager');
        Sanctum::actingAs($manager);
        $this->getJson('/api/v1/me/profile')->assertOk()->assertJsonMissingPath('data.staff.user');

        [$admin] = $this->employee('administrator');
        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/me/profile')->assertOk()->assertJson(['data' => ['staff' => ['user' => [
            'public_id' => $admin->public_id,
            'email' => $admin->email,
            'status' => 'active',
        ]]]]);
    }

    public function test_no_internal_numeric_id_appears_anywhere(): void
    {
        [$user] = $this->employee('administrator');
        Sanctum::actingAs($user);

        $keys = $this->allKeys($this->getJson('/api/v1/me/profile')->json());

        foreach (['id', 'user_id', 'role_id', 'department_id', 'team_id', 'position_id', 'manager_id', 'password', 'remember_token'] as $forbidden) {
            $this->assertNotContains($forbidden, $keys);
        }
    }

    public function test_missing_organization_placement_and_manager_are_null(): void
    {
        $user = User::factory()->staff()->create();
        Staff::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/me/profile')
            ->assertOk()
            ->assertJson(['data' => ['staff' => [
                'department' => null,
                'team' => null,
                'position' => null,
                'manager' => null,
            ]]]);
    }

    public function test_a_non_active_employment_status_still_returns_the_own_record(): void
    {
        $user = User::factory()->staff()->create();
        $staff = Staff::factory()->separated()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/me/profile')
            ->assertOk()
            ->assertJson(['data' => ['staff' => [
                'public_id' => $staff->public_id,
                'status' => 'separated',
            ]]]);
    }

    // --- No profile / no role -------------------------------------------------

    public function test_a_user_without_a_staff_record_gets_200_with_null_staff(): void
    {
        $admin = User::factory()->administrator()->create();
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/me/profile')
            ->assertOk()
            ->assertExactJson(['data' => [
                'user' => [
                    'public_id' => $admin->public_id,
                    'name' => $admin->name,
                    'email' => $admin->email,
                    'role' => 'administrator',
                ],
                'staff' => null,
            ]]);
    }

    public function test_no_permission_is_needed_a_user_with_no_role_still_gets_their_own_profile(): void
    {
        $user = User::factory()->create();
        $staff = Staff::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        // The same user cannot read the directory (no staff.view)…
        $this->getJson("/api/v1/staff/{$staff->public_id}")->assertForbidden();

        // …but can always read their own profile.
        $this->getJson('/api/v1/me/profile')
            ->assertOk()
            ->assertJson(['data' => [
                'user' => ['public_id' => $user->public_id, 'role' => null],
                'staff' => ['public_id' => $staff->public_id],
            ]]);
    }

    // --- Self-scoping ------------------------------------------------------

    public function test_request_parameters_can_never_change_the_subject(): void
    {
        [$user, $staff] = $this->employee();
        [$other, $otherStaff] = $this->employee();
        Sanctum::actingAs($user);

        $query = http_build_query([
            'user' => $other->public_id,
            'user_id' => $other->id,
            'staff' => $otherStaff->public_id,
            'staff_id' => $otherStaff->id,
            'public_id' => $otherStaff->public_id,
        ]);

        $this->getJson("/api/v1/me/profile?{$query}")
            ->assertOk()
            ->assertJsonPath('data.user.public_id', $user->public_id)
            ->assertJsonPath('data.staff.public_id', $staff->public_id)
            ->assertDontSee($otherStaff->public_id)
            ->assertDontSee($other->email);
    }

    public function test_an_administrator_sees_only_their_own_record(): void
    {
        [$admin, $adminStaff] = $this->employee('administrator');
        [, $someoneElse] = $this->employee();
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/me/profile')
            ->assertOk()
            ->assertJsonPath('data.staff.public_id', $adminStaff->public_id)
            ->assertDontSee($someoneElse->public_id);
    }

    // --- Performance ----------------------------------------------------------

    public function test_the_query_count_is_constant(): void
    {
        [$user] = $this->employee();
        Sanctum::actingAs($user);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/v1/me/profile')->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        // user→role, user→staff, and staff's six eager-loaded relations,
        // plus Sanctum's token lookup — independent of company size.
        $this->assertLessThanOrEqual(12, $count);
    }
}
