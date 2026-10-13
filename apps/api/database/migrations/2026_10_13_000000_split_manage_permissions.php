<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Phase 35A (DEC-065): split five `.manage` permissions into separate
 * add / edit / delete permissions. A data migration, not a schema change:
 * every role holding an old permission is given all of its parts (so no
 * role gains or loses anything), then the old permission is removed.
 * On a fresh database the old permissions don't exist and nothing
 * happens here; RolePermissionSeeder creates the new catalog, as it does
 * every permission.
 */
return new class extends Migration
{
    /**
     * @var array<string, array{label: string, parts: array<string, string>}>
     */
    private const SPLITS = [
        'staff.manage' => [
            'label' => 'Create, update, and delete staff records',
            'parts' => [
                'staff.create' => 'Add staff records',
                'staff.update' => 'Edit staff records',
                'staff.delete' => 'Delete staff records',
            ],
        ],
        'clients.manage' => [
            'label' => 'Create, update, and delete clients and contacts',
            'parts' => [
                'clients.create' => 'Add clients',
                'clients.update' => 'Edit clients and their contacts',
                'clients.delete' => 'Delete clients',
            ],
        ],
        'projects.manage' => [
            'label' => 'Create, update, and delete projects and project memberships',
            'parts' => [
                'projects.create' => 'Add projects',
                'projects.update' => 'Edit projects and their milestones',
                'projects.delete' => 'Delete projects',
                'projects.members' => 'Add, change, and remove project members and leads',
            ],
        ],
        'tasks.manage' => [
            'label' => 'Create, update, and delete any task',
            'parts' => [
                'tasks.create' => 'Add tasks in any project',
                'tasks.update' => 'Edit any task (all fields, including the assignee)',
                'tasks.delete' => 'Delete tasks',
            ],
        ],
        'announcements.manage' => [
            'label' => 'Create, edit, publish, archive, and delete announcements',
            'parts' => [
                'announcements.view-all' => 'View all announcements, including drafts and archived',
                'announcements.create' => 'Add and edit announcement drafts',
                'announcements.publish' => 'Publish and archive announcements',
                'announcements.delete' => 'Delete announcements',
            ],
        ],
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            foreach (self::SPLITS as $old => $split) {
                $oldId = DB::table('permissions')->where('name', $old)->value('id');
                if ($oldId === null) {
                    continue;
                }

                $partIds = [];
                foreach ($split['parts'] as $name => $label) {
                    $partIds[] = $this->permissionId($name, $label);
                }

                foreach (DB::table('role_permissions')->where('permission_id', $oldId)->pluck('role_id') as $roleId) {
                    foreach ($partIds as $partId) {
                        DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $partId]);
                    }
                }

                DB::table('role_permissions')->where('permission_id', $oldId)->delete();
                DB::table('permissions')->where('id', $oldId)->delete();
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            foreach (self::SPLITS as $old => $split) {
                $partIds = DB::table('permissions')->whereIn('name', array_keys($split['parts']))->pluck('id');
                if ($partIds->isEmpty()) {
                    continue;
                }

                $oldId = $this->permissionId($old, $split['label']);

                // A role gets the old permission back only if it held every part.
                $roleIds = DB::table('role_permissions')
                    ->whereIn('permission_id', $partIds)
                    ->select('role_id')
                    ->groupBy('role_id')
                    ->havingRaw('count(*) = ?', [count($split['parts'])])
                    ->pluck('role_id');
                foreach ($roleIds as $roleId) {
                    DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $oldId]);
                }

                DB::table('role_permissions')->whereIn('permission_id', $partIds)->delete();
                DB::table('permissions')->whereIn('id', $partIds)->delete();
            }
        });
    }

    private function permissionId(string $name, string $label): int
    {
        $id = DB::table('permissions')->where('name', $name)->value('id');
        if ($id !== null) {
            return (int) $id;
        }

        return (int) DB::table('permissions')->insertGetId([
            'name' => $name,
            'label' => $label,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
