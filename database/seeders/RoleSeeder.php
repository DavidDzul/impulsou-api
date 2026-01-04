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
        $campusRole = Role::create(['name' => 'ROOT_CAMPUS']);
        $yucatanRole = Role::create(['name' => 'YUCATAN']);
        $attendanceRole = Role::create(['name' => 'ATTENDANCE']);
        $adminStudentRole = Role::create(['name' => 'ADMIN_STUDENT']);
        $rootJobRole = Role::create(['name' => 'ROOT_JOB']);
        $adminJobRole = Role::create(['name' => 'ADMIN_JOB']);

        /** PANEL DE USUARIO */
        Permission::create(['name' => 'CANDIDATES_VIEW'])->syncRoles([$bronzeRole, $silverRole, $goldRole, $platinumRole, $diamondRole]);
        Permission::create(['name' => 'CREATE_VACANT_JR'])->syncRoles([$diamondRole]);

        /** PANEL ADMINISTRATIVO */
        Permission::create(['name' => 'PS_GROUP_USERS'])->syncRoles([$rootRole, $campusRole, $yucatanRole, $adminStudentRole, $rootJobRole, $adminJobRole]);
        Permission::create(['name' => 'PS_USERS'])->syncRoles([$rootRole, $campusRole, $adminStudentRole]);
        Permission::create(['name' => 'PS_READ_USERS'])->syncRoles([$rootRole, $campusRole, $adminStudentRole]);
        Permission::create(['name' => 'PS_CREATE_USERS'])->syncRoles([$rootRole, $campusRole, $adminStudentRole]);
        Permission::create(['name' => 'PS_EDIT_USERS'])->syncRoles([$rootRole, $campusRole, $adminStudentRole]);

        Permission::create(['name' => 'PS_GRADUATES'])->syncRoles([$rootRole, $campusRole, $adminStudentRole]);
        Permission::create(['name' => 'PS_READ_GRADUATES'])->syncRoles([$rootRole, $campusRole, $adminStudentRole]);
        Permission::create(['name' => 'PS_CREATE_GRADUATES'])->syncRoles([$rootRole, $campusRole, $adminStudentRole]);
        Permission::create(['name' => 'PS_EDIT_GRADUATES'])->syncRoles([$rootRole, $campusRole, $adminStudentRole]);

        Permission::create(['name' => 'PS_BUSINESS'])->syncRoles([$rootRole, $campusRole, $yucatanRole, $rootJobRole, $adminJobRole]);
        Permission::create(['name' => 'PS_READ_BUSINESS'])->syncRoles([$rootRole, $campusRole, $yucatanRole, $rootJobRole, $adminJobRole]);
        Permission::create(['name' => 'PS_CREATE_BUSINESS'])->syncRoles([$rootRole, $campusRole, $rootJobRole, $adminJobRole]);
        Permission::create(['name' => 'PS_EDIT_BUSINESS'])->syncRoles([$rootRole, $campusRole, $rootJobRole, $adminJobRole]);

        Permission::create(['name' => 'PS_GROUP_JOBS'])->syncRoles([$rootRole, $campusRole, $yucatanRole, $rootJobRole, $adminJobRole]);
        Permission::create(['name' => 'PS_VACANT'])->syncRoles([$rootRole, $campusRole, $yucatanRole, $rootJobRole, $adminJobRole]);
        Permission::create(['name' => 'PS_READ_VACANT'])->syncRoles([$rootRole, $campusRole, $yucatanRole, $rootJobRole, $adminJobRole]);
        Permission::create(['name' => 'PS_CREATE_VACANT'])->syncRoles([$rootRole, $campusRole, $rootJobRole, $adminJobRole]);
        Permission::create(['name' => 'PS_EDIT_VACANT'])->syncRoles([$rootRole, $campusRole, $rootJobRole, $adminJobRole]);

        Permission::create(['name' => 'PS_APPLICATION'])->syncRoles([$rootRole, $campusRole, $yucatanRole, $rootJobRole, $adminJobRole]);
        Permission::create(['name' => 'PS_READ_APPLICATION'])->syncRoles([$rootRole, $campusRole, $yucatanRole, $rootJobRole, $adminJobRole]);
        Permission::create(['name' => 'PS_EDIT_APPLICATION'])->syncRoles([$rootRole, $campusRole, $rootJobRole, $adminJobRole]);

        Permission::create(['name' => 'PS_GROUP_CONFIG'])->syncRoles([$rootRole, $campusRole, $adminStudentRole, $rootJobRole]);
        Permission::create(['name' => 'PS_GRAPHICS'])->syncRoles([$rootRole, $adminStudentRole, $rootJobRole]);

        Permission::create(['name' => 'PS_GROUP_ATTENDANCE'])->syncRoles([$rootRole, $campusRole, $attendanceRole, $adminStudentRole]);
        Permission::create(['name' => 'PS_CHECK'])->syncRoles([$rootRole, $campusRole, $attendanceRole, $adminStudentRole]);
        Permission::create(['name' => 'PS_CLASSES'])->syncRoles([$rootRole, $campusRole, $adminStudentRole]);

        Permission::create(['name' => 'PS_GENERATIONS'])->syncRoles([$rootRole, $campusRole, $adminStudentRole]);
        Permission::create(['name' => 'PS_ROLES'])->syncRoles([$rootRole, $rootJobRole]);
        Permission::create(['name' => 'PS_NOTICES'])->syncRoles([$rootRole, $adminStudentRole,]);
    }
}
