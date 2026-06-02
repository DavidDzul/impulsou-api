<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class ScholarshipPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $rootRole = Role::where('name', 'ROOT')->firstOrFail();

        $permissions = [
            'PS_GROUP_SCHOLARSHIPS',
            'PS_SCHOLARSHIPS_ATENCION',
            'PS_SCHOLARSHIPS_PEDAGOGIA',
        ];

        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name]);
            if (!$rootRole->hasPermissionTo($permission)) {
                $rootRole->givePermissionTo($permission);
            }
        }
    }
}
