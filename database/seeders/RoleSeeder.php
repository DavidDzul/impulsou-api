<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        //
        Role::create(['name' => 'BASIC']);
        $bronzeRole = Role::create(['name' => 'BRONZE']);
        $silverRole = Role::create(['name' => 'SILVER']);
        $goldRole = Role::create(['name' => 'GOLD']);
        $platinumRole = Role::create(['name' => 'PLATINUM']);
        $diamondRole = Role::create(['name' => 'DIAMOND']);

        $rootRole = Role::create(['name' => 'ROOT']);
        $rootCampusRole = Role::create(['name' => 'ROOT_CAMPUS']);
        $yucatanRole = Role::create(['name' => 'YUCATAN']);
        $attendanceRole = Role::create(['name' => 'ATTENDANCE']);
        $adminStudentRole = Role::create(['name' => 'ADMIN_STUDENT']);
        $rootJobRole = Role::create(['name' => 'ROOT_JOB']);
        $adminJobRole = Role::create(['name' => 'ADMIN_JOB']);
        // This entry uses updateOrCreate so it is safe to seed onto an already-populated DB
        // (e.g. via `php artisan tinker`, targeting only this line). The rest of this seeder
        // class is NOT idempotent — do not run the full class via `db:seed` on a non-fresh DB.
        // updateOrCreate() matches by `name` only and forces `type` on BOTH create AND update,
        // which is required here: control-accesos-administration-panel PR1 (B0 retag, design
        // obs #1593) needs this line to retag a ROOT_ADMINISTRATION row that may already exist
        // with type='USER' (the column default) from prior seeding — firstOrCreate() cannot do
        // this, since it only sets attributes on CREATE, never on an existing match.
        $rootAdministrationRole = Role::updateOrCreate(['name' => 'ROOT_ADMINISTRATION'], ['type' => 'ADMINISTRATION']);

        /** PANEL DE USUARIO */
        Permission::create(['name' => 'CANDIDATES_VIEW'])->syncRoles([$bronzeRole, $silverRole, $goldRole, $platinumRole, $diamondRole]);
        Permission::create(['name' => 'CREATE_VACANT_JR'])->syncRoles([$diamondRole]);

        /** PANEL ADMINISTRATIVO */
        Permission::create(['name' => 'PS_GROUP_USERS'])->syncRoles([$rootRole, $rootCampusRole, $yucatanRole, $adminStudentRole, $rootJobRole, $adminJobRole]);
        Permission::create(['name' => 'PS_USERS'])->syncRoles([$rootRole, $rootCampusRole, $adminStudentRole]);
        Permission::create(['name' => 'PS_READ_USERS'])->syncRoles([$rootRole, $rootCampusRole, $adminStudentRole]);
        Permission::create(['name' => 'PS_CREATE_USERS'])->syncRoles([$rootRole, $rootCampusRole, $adminStudentRole]);
        Permission::create(['name' => 'PS_EDIT_USERS'])->syncRoles([$rootRole, $rootCampusRole, $adminStudentRole]);

        Permission::create(['name' => 'PS_GRADUATES'])->syncRoles([$rootRole, $rootCampusRole, $adminStudentRole]);
        Permission::create(['name' => 'PS_READ_GRADUATES'])->syncRoles([$rootRole, $rootCampusRole, $adminStudentRole]);
        Permission::create(['name' => 'PS_CREATE_GRADUATES'])->syncRoles([$rootRole, $rootCampusRole, $adminStudentRole]);
        Permission::create(['name' => 'PS_EDIT_GRADUATES'])->syncRoles([$rootRole, $rootCampusRole, $adminStudentRole]);

        Permission::create(['name' => 'PS_BUSINESS'])->syncRoles([$rootRole, $rootCampusRole, $yucatanRole, $rootJobRole, $adminJobRole]);
        Permission::create(['name' => 'PS_READ_BUSINESS'])->syncRoles([$rootRole, $rootCampusRole, $yucatanRole, $rootJobRole, $adminJobRole]);
        Permission::create(['name' => 'PS_CREATE_BUSINESS'])->syncRoles([$rootRole, $rootCampusRole, $rootJobRole, $adminJobRole]);
        Permission::create(['name' => 'PS_EDIT_BUSINESS'])->syncRoles([$rootRole, $rootCampusRole, $rootJobRole, $adminJobRole]);

        Permission::create(['name' => 'PS_GROUP_JOBS'])->syncRoles([$rootRole, $rootCampusRole, $yucatanRole, $rootJobRole, $adminJobRole]);
        Permission::create(['name' => 'PS_VACANT'])->syncRoles([$rootRole, $rootCampusRole, $yucatanRole, $rootJobRole, $adminJobRole]);
        Permission::create(['name' => 'PS_READ_VACANT'])->syncRoles([$rootRole, $rootCampusRole, $yucatanRole, $rootJobRole, $adminJobRole]);
        Permission::create(['name' => 'PS_CREATE_VACANT'])->syncRoles([$rootRole, $rootCampusRole, $rootJobRole, $adminJobRole]);
        Permission::create(['name' => 'PS_EDIT_VACANT'])->syncRoles([$rootRole, $rootCampusRole, $rootJobRole, $adminJobRole]);

        Permission::create(['name' => 'PS_APPLICATION'])->syncRoles([$rootRole, $rootCampusRole, $yucatanRole, $rootJobRole, $adminJobRole]);
        Permission::create(['name' => 'PS_READ_APPLICATION'])->syncRoles([$rootRole, $rootCampusRole, $yucatanRole, $rootJobRole, $adminJobRole]);
        Permission::create(['name' => 'PS_EDIT_APPLICATION'])->syncRoles([$rootRole, $rootCampusRole, $rootJobRole, $adminJobRole]);

        Permission::create(['name' => 'PS_GROUP_CONFIG'])->syncRoles([$rootRole, $rootCampusRole, $adminStudentRole, $rootJobRole]);
        Permission::create(['name' => 'PS_GRAPHICS'])->syncRoles([$rootRole, $rootJobRole]);

        Permission::create(['name' => 'PS_GROUP_ATTENDANCE'])->syncRoles([$rootRole, $rootCampusRole, $attendanceRole, $adminStudentRole]);
        Permission::create(['name' => 'PS_CHECK'])->syncRoles([$rootRole, $rootCampusRole, $attendanceRole, $adminStudentRole]);
        Permission::create(['name' => 'PS_CLASSES'])->syncRoles([$rootRole, $rootCampusRole, $adminStudentRole]);

        Permission::create(['name' => 'PS_GENERATIONS'])->syncRoles([$rootRole, $rootCampusRole, $adminStudentRole]);
        Permission::create(['name' => 'PS_ROLES'])->syncRoles([$rootRole, $rootJobRole]);
        Permission::create(['name' => 'PS_NOTICES'])->syncRoles([$rootRole, $adminStudentRole, $rootCampusRole]);

        Permission::create(['name' => 'PS_GROUP_SCHOLARSHIPS'])->syncRoles([$rootRole]);
        Permission::create(['name' => 'PS_SCHOLARSHIPS_ATENCION'])->syncRoles([$rootRole]);
        Permission::create(['name' => 'PS_SCHOLARSHIPS_PEDAGOGIA'])->syncRoles([$rootRole]);

        /** PANEL ADMINISTRATION (administration-panel) */
        // updateOrCreate here mirrors the ROOT_ADMINISTRATION role above: this grant must be
        // safe to re-run without creating duplicate permission rows or duplicate role_has_permissions
        // pivot rows (syncRoles is idempotent by nature — it replaces the role set, not append),
        // and must retag an already-existing row (type='USER' default) to type='ADMINISTRATION'
        // (control-accesos-administration-panel PR1, B0 retag, design obs #1593).
        Permission::updateOrCreate(['name' => 'ADM_READ_USERS'], ['type' => 'ADMINISTRATION'])->syncRoles([$rootAdministrationRole]);
        // Payment data (becarios-payment-config, design D5/D9): read/write split
        // granted to ROOT_ADMINISTRATION ONLY (confirmed by user, not ROOT).
        // updateOrCreate keeps these two lines safe to re-apply in isolation via
        // tinker on a non-fresh DB, and retags pre-existing rows, same as ADM_READ_USERS above.
        Permission::updateOrCreate(['name' => 'ADM_READ_PAYMENT_DATA'], ['type' => 'ADMINISTRATION'])->syncRoles([$rootAdministrationRole]);
        Permission::updateOrCreate(['name' => 'ADM_EDIT_PAYMENT_DATA'], ['type' => 'ADMINISTRATION'])->syncRoles([$rootAdministrationRole]);
        // Control (Roles + Accesos), control-accesos-administration-panel PR2
        // (design obs #1593, tasks obs #1594 Phase 2 note): these 4 were
        // originally planned for PR1 but deferred here, since the
        // controllers/routes that require them are introduced in this PR
        // (ADM_READ_ROLES/ADM_MANAGE_ROLES) and PR8+ (ADM_READ_ADMINS/
        // ADM_MANAGE_ADMINS — seeded now, consumed later). Granted only to
        // ROOT_ADMINISTRATION, same updateOrCreate rationale as above.
        Permission::updateOrCreate(['name' => 'ADM_READ_ROLES'], ['type' => 'ADMINISTRATION'])->syncRoles([$rootAdministrationRole]);
        Permission::updateOrCreate(['name' => 'ADM_MANAGE_ROLES'], ['type' => 'ADMINISTRATION'])->syncRoles([$rootAdministrationRole]);
        Permission::updateOrCreate(['name' => 'ADM_READ_ADMINS'], ['type' => 'ADMINISTRATION'])->syncRoles([$rootAdministrationRole]);
        Permission::updateOrCreate(['name' => 'ADM_MANAGE_ADMINS'], ['type' => 'ADMINISTRATION'])->syncRoles([$rootAdministrationRole]);
    }
}
