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

        $rootRole = Role::create(['name' => 'ADMIN_ROOT']);
        $campusRole = Role::create(['name' => 'ADMIN_CAMPUS']);
        $yucatanRole = Role::create(['name' => 'ADMIN_YUCATAN']);

        /** PANEL DE USUARIO */
        Permission::create(['name' => 'CANDIDATES_VIEW'])->syncRoles([$bronzeRole, $silverRole, $goldRole, $platinumRole, $diamondRole]);
        Permission::create(['name' => 'CREATE_VACANT_JR'])->syncRoles([$diamondRole]);

        /** PANEL ADMINISTRATIVO */
        Permission::create(['name' => 'ADMIN_GROUP_USERS'])->syncRoles([$rootRole, $campusRole, $yucatanRole]);

        Permission::create(['name' => 'ADMIN_USERS'])->syncRoles([$rootRole, $campusRole]);
        Permission::create(['name' => 'ADMIN_READ_USERS'])->syncRoles([$rootRole, $campusRole]);
        Permission::create(['name' => 'ADMIN_CREATE_USERS'])->syncRoles([$rootRole, $campusRole]);
        Permission::create(['name' => 'ADMIN_EDIT_USERS'])->syncRoles([$rootRole, $campusRole]);

        Permission::create(['name' => 'ADMIN_GRADUATES'])->syncRoles([$rootRole, $campusRole]);
        Permission::create(['name' => 'ADMIN_READ_GRADUATES'])->syncRoles([$rootRole, $campusRole]);
        Permission::create(['name' => 'ADMIN_CREATE_GRADUATES'])->syncRoles([$rootRole, $campusRole]);
        Permission::create(['name' => 'ADMIN_EDIT_GRADUATES'])->syncRoles([$rootRole, $campusRole]);

        Permission::create(['name' => 'ADMIN_BUSINESS'])->syncRoles([$rootRole, $campusRole, $yucatanRole]);
        Permission::create(['name' => 'ADMIN_READ_BUSINESS'])->syncRoles([$rootRole, $campusRole, $yucatanRole]);
        Permission::create(['name' => 'ADMIN_CREATE_BUSINESS'])->syncRoles([$rootRole, $campusRole]);
        Permission::create(['name' => 'ADMIN_EDIT_BUSINESS'])->syncRoles([$rootRole, $campusRole]);

        Permission::create(['name' => 'ADMIN_GROUP_JOBS'])->syncRoles([$rootRole, $campusRole, $yucatanRole]);

        Permission::create(['name' => 'ADMIN_VACANT'])->syncRoles([$rootRole, $campusRole, $yucatanRole]);
        Permission::create(['name' => 'ADMIN_READ_VACANT'])->syncRoles([$rootRole, $campusRole, $yucatanRole]);
        Permission::create(['name' => 'ADMIN_CREATE_VACANT'])->syncRoles([$rootRole, $campusRole]);
        Permission::create(['name' => 'ADMIN_EDIT_VACANT'])->syncRoles([$rootRole, $campusRole]);

        Permission::create(['name' => 'ADMIN_APPLICATION'])->syncRoles([$rootRole, $campusRole, $yucatanRole]);
        Permission::create(['name' => 'ADMIN_READ_APPLICATION'])->syncRoles([$rootRole, $campusRole, $yucatanRole]);
        Permission::create(['name' => 'ADMIN_EDIT_APPLICATION'])->syncRoles([$rootRole, $campusRole]);

        Permission::create(['name' => 'ADMIN_GROUP_CONFIG'])->syncRoles([$rootRole, $campusRole]);
    }
}