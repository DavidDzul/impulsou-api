<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

/**
 * Local/test seed data only. Types mirror staging (design empresas-autorizacion-api B1):
 * USER = business tiers + CANDIDATES_VIEW/CREATE_VACANT_JR; ADMIN = psicol staff roles + PS_*;
 * ADMINISTRATION = ROOT_ADMINISTRATION + ADM_*. Every line is updateOrCreate, so a re-run
 * on a local DB is safe and retags old rows.
 *
 * NEVER run this seeder on staging or production: syncRoles() REPLACES each permission's
 * grant set with the matrix below and would wipe any drift/customisation made there.
 * Staging/prod changes go through the docs/deploy-staging SQL scripts instead.
 */
class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Hard stop, not only a docblock: syncRoles() below would replace live grants.
        if (app()->environment(['production', 'staging'])) {
            throw new \RuntimeException(sprintf(
                'RoleSeeder refused to run in the "%s" environment: it overwrites role/permission grants. Use the docs/deploy-staging SQL scripts instead.',
                app()->environment()
            ));
        }

        Role::updateOrCreate(['name' => 'BASIC'], ['type' => 'USER']);
        $bronzeRole = Role::updateOrCreate(['name' => 'BRONZE'], ['type' => 'USER']);
        $silverRole = Role::updateOrCreate(['name' => 'SILVER'], ['type' => 'USER']);
        $goldRole = Role::updateOrCreate(['name' => 'GOLD'], ['type' => 'USER']);
        $platinumRole = Role::updateOrCreate(['name' => 'PLATINUM'], ['type' => 'USER']);
        $diamondRole = Role::updateOrCreate(['name' => 'DIAMOND'], ['type' => 'USER']);

        $rootRole = Role::updateOrCreate(['name' => 'ROOT'], ['type' => 'ADMIN']);
        $rootCampusRole = Role::updateOrCreate(['name' => 'ROOT_CAMPUS'], ['type' => 'ADMIN']);
        $yucatanRole = Role::updateOrCreate(['name' => 'YUCATAN'], ['type' => 'ADMIN']);
        $attendanceRole = Role::updateOrCreate(['name' => 'ATTENDANCE'], ['type' => 'ADMIN']);
        $adminStudentRole = Role::updateOrCreate(['name' => 'ADMIN_STUDENT'], ['type' => 'ADMIN']);
        $rootJobRole = Role::updateOrCreate(['name' => 'ROOT_JOB'], ['type' => 'ADMIN']);
        $adminJobRole = Role::updateOrCreate(['name' => 'ADMIN_JOB'], ['type' => 'ADMIN']);
        // Same updateOrCreate rationale: matches by `name` and forces `type` on create AND update
        // (control-accesos-administration-panel PR1 B0 retag, design obs #1593).
        $rootAdministrationRole = Role::updateOrCreate(['name' => 'ROOT_ADMINISTRATION'], ['type' => 'ADMINISTRATION']);

        /** PANEL DE USUARIO */
        Permission::updateOrCreate(['name' => 'CANDIDATES_VIEW'], ['type' => 'USER'])->syncRoles([$bronzeRole, $silverRole, $goldRole, $platinumRole, $diamondRole]);
        Permission::updateOrCreate(['name' => 'CREATE_VACANT_JR'], ['type' => 'USER'])->syncRoles([$diamondRole]);

        /** PANEL ADMINISTRATIVO */
        Permission::updateOrCreate(['name' => 'PS_GROUP_USERS'], ['type' => 'ADMIN', 'module' => 'Usuarios', 'description' => 'Mostrar el menú Usuarios'])->syncRoles([$rootRole, $rootCampusRole, $yucatanRole, $adminStudentRole, $rootJobRole, $adminJobRole]);
        Permission::updateOrCreate(['name' => 'PS_USERS'], ['type' => 'ADMIN', 'module' => 'Usuarios', 'description' => 'Entrar a becarios y egresados'])->syncRoles([$rootRole, $rootCampusRole, $adminStudentRole]);
        Permission::updateOrCreate(['name' => 'PS_READ_USERS'], ['type' => 'ADMIN', 'module' => 'Usuarios', 'description' => 'Ver la lista de becarios'])->syncRoles([$rootRole, $rootCampusRole, $adminStudentRole]);
        Permission::updateOrCreate(['name' => 'PS_CREATE_USERS'], ['type' => 'ADMIN', 'module' => 'Usuarios', 'description' => 'Registrar becarios'])->syncRoles([$rootRole, $rootCampusRole, $adminStudentRole]);
        Permission::updateOrCreate(['name' => 'PS_EDIT_USERS'], ['type' => 'ADMIN', 'module' => 'Usuarios', 'description' => 'Editar becarios'])->syncRoles([$rootRole, $rootCampusRole, $adminStudentRole]);

        Permission::updateOrCreate(['name' => 'PS_GRADUATES'], ['type' => 'ADMIN', 'module' => 'Usuarios', 'description' => 'Entrar a egresados'])->syncRoles([$rootRole, $rootCampusRole, $adminStudentRole]);
        Permission::updateOrCreate(['name' => 'PS_READ_GRADUATES'], ['type' => 'ADMIN', 'module' => 'Usuarios', 'description' => 'Ver la lista de egresados'])->syncRoles([$rootRole, $rootCampusRole, $adminStudentRole]);
        Permission::updateOrCreate(['name' => 'PS_CREATE_GRADUATES'], ['type' => 'ADMIN', 'module' => 'Usuarios', 'description' => 'Registrar egresados'])->syncRoles([$rootRole, $rootCampusRole, $adminStudentRole]);
        Permission::updateOrCreate(['name' => 'PS_EDIT_GRADUATES'], ['type' => 'ADMIN', 'module' => 'Usuarios', 'description' => 'Editar egresados'])->syncRoles([$rootRole, $rootCampusRole, $adminStudentRole]);

        Permission::updateOrCreate(['name' => 'PS_BUSINESS'], ['type' => 'ADMIN', 'module' => 'Empresas', 'description' => 'Entrar a empresas'])->syncRoles([$rootRole, $rootCampusRole, $yucatanRole, $rootJobRole, $adminJobRole]);
        Permission::updateOrCreate(['name' => 'PS_READ_BUSINESS'], ['type' => 'ADMIN', 'module' => 'Empresas', 'description' => 'Ver la lista de empresas'])->syncRoles([$rootRole, $rootCampusRole, $yucatanRole, $rootJobRole, $adminJobRole]);
        Permission::updateOrCreate(['name' => 'PS_CREATE_BUSINESS'], ['type' => 'ADMIN', 'module' => 'Empresas', 'description' => 'Registrar empresas'])->syncRoles([$rootRole, $rootCampusRole, $rootJobRole, $adminJobRole]);
        Permission::updateOrCreate(['name' => 'PS_EDIT_BUSINESS'], ['type' => 'ADMIN', 'module' => 'Empresas', 'description' => 'Editar empresas'])->syncRoles([$rootRole, $rootCampusRole, $rootJobRole, $adminJobRole]);

        Permission::updateOrCreate(['name' => 'PS_GROUP_JOBS'], ['type' => 'ADMIN', 'module' => 'Vinculación laboral', 'description' => 'Mostrar el menú Vinculación laboral'])->syncRoles([$rootRole, $rootCampusRole, $yucatanRole, $rootJobRole, $adminJobRole]);
        Permission::updateOrCreate(['name' => 'PS_VACANT'], ['type' => 'ADMIN', 'module' => 'Vinculación laboral', 'description' => 'Entrar a vacantes'])->syncRoles([$rootRole, $rootCampusRole, $yucatanRole, $rootJobRole, $adminJobRole]);
        Permission::updateOrCreate(['name' => 'PS_READ_VACANT'], ['type' => 'ADMIN', 'module' => 'Vinculación laboral', 'description' => 'Ver la lista de vacantes'])->syncRoles([$rootRole, $rootCampusRole, $yucatanRole, $rootJobRole, $adminJobRole]);
        Permission::updateOrCreate(['name' => 'PS_CREATE_VACANT'], ['type' => 'ADMIN', 'module' => 'Vinculación laboral', 'description' => 'Crear vacantes'])->syncRoles([$rootRole, $rootCampusRole, $rootJobRole, $adminJobRole]);
        Permission::updateOrCreate(['name' => 'PS_EDIT_VACANT'], ['type' => 'ADMIN', 'module' => 'Vinculación laboral', 'description' => 'Editar vacantes'])->syncRoles([$rootRole, $rootCampusRole, $rootJobRole, $adminJobRole]);

        Permission::updateOrCreate(['name' => 'PS_APPLICATION'], ['type' => 'ADMIN', 'module' => 'Vinculación laboral', 'description' => 'Entrar a postulaciones'])->syncRoles([$rootRole, $rootCampusRole, $yucatanRole, $rootJobRole, $adminJobRole]);
        Permission::updateOrCreate(['name' => 'PS_READ_APPLICATION'], ['type' => 'ADMIN', 'module' => 'Vinculación laboral', 'description' => 'Ver la lista de postulaciones'])->syncRoles([$rootRole, $rootCampusRole, $yucatanRole, $rootJobRole, $adminJobRole]);
        Permission::updateOrCreate(['name' => 'PS_EDIT_APPLICATION'], ['type' => 'ADMIN', 'module' => 'Vinculación laboral', 'description' => 'Editar postulaciones'])->syncRoles([$rootRole, $rootCampusRole, $rootJobRole, $adminJobRole]);

        Permission::updateOrCreate(['name' => 'PS_GROUP_CONFIG'], ['type' => 'ADMIN', 'module' => 'Configuración', 'description' => 'Mostrar el menú Configuración'])->syncRoles([$rootRole, $rootCampusRole, $adminStudentRole, $rootJobRole]);
        Permission::updateOrCreate(['name' => 'PS_GRAPHICS'], ['type' => 'ADMIN', 'module' => 'Vinculación laboral', 'description' => 'Entrar a áreas e informes'])->syncRoles([$rootRole, $rootJobRole]);

        Permission::updateOrCreate(['name' => 'PS_GROUP_ATTENDANCE'], ['type' => 'ADMIN', 'module' => 'Asistencias', 'description' => 'Mostrar el menú Asistencias'])->syncRoles([$rootRole, $rootCampusRole, $attendanceRole, $adminStudentRole]);
        Permission::updateOrCreate(['name' => 'PS_CHECK'], ['type' => 'ADMIN', 'module' => 'Asistencias', 'description' => 'Entrar al checador'])->syncRoles([$rootRole, $rootCampusRole, $attendanceRole, $adminStudentRole]);
        Permission::updateOrCreate(['name' => 'PS_CLASSES'], ['type' => 'ADMIN', 'module' => 'Asistencias', 'description' => 'Entrar a las sesiones de formación'])->syncRoles([$rootRole, $rootCampusRole, $adminStudentRole]);

        Permission::updateOrCreate(['name' => 'PS_GENERATIONS'], ['type' => 'ADMIN', 'module' => 'Configuración', 'description' => 'Entrar a generaciones'])->syncRoles([$rootRole, $rootCampusRole, $adminStudentRole]);
        // Configurable gate of /roles (business plans only): ROOT always passes; ROOT_JOB holds it as in staging.
        Permission::updateOrCreate(['name' => 'PS_ROLES'], ['type' => 'ADMIN', 'module' => 'Vinculación laboral', 'description' => 'Entrar a roles de planes de empresa'])->syncRoles([$rootRole, $rootJobRole]);
        Permission::updateOrCreate(['name' => 'PS_NOTICES'], ['type' => 'ADMIN', 'module' => 'Asistencias', 'description' => 'Entrar a avisos'])->syncRoles([$rootRole, $adminStudentRole, $rootCampusRole]);

        Permission::updateOrCreate(['name' => 'PS_GROUP_SCHOLARSHIPS'], ['type' => 'ADMIN', 'module' => 'Becas', 'description' => 'Mostrar el menú Becas'])->syncRoles([$rootRole]);
        Permission::updateOrCreate(['name' => 'PS_SCHOLARSHIPS_ATENCION'], ['type' => 'ADMIN', 'module' => 'Becas', 'description' => 'Entrar a la verificación de refrendos'])->syncRoles([$rootRole]);
        Permission::updateOrCreate(['name' => 'PS_SCHOLARSHIPS_PEDAGOGIA'], ['type' => 'ADMIN', 'module' => 'Becas', 'description' => 'Entrar a la aprobación de refrendos'])->syncRoles([$rootRole]);

        // Effective only for MERIDA staff (User::hasGlobalBusinessVisibility). Type ADMIN mirrors
        // the staging PS_* permissions. Default grant: ROOT only. ROOT_JOB is intentionally not
        // granted: its global visibility is hardcoded.
        Permission::updateOrCreate(
            ['name' => 'PS_VIEW_ALL_CAMPUSES'],
            ['type' => 'ADMIN', 'module' => 'Empresas', 'description' => 'Ver empresas de todas las sedes (solo personal de Mérida)']
        )->syncRoles([$rootRole]);

        // Gate of the psicol-panel "Administradores" module (administradores-psicol). ROOT only and never
        // grantable to staff roles. Its module/description copy lives in the DB like every PS_* (administradores-psicol).
        Permission::updateOrCreate(['name' => 'PS_MANAGE_ADMINS'], ['type' => 'ADMIN', 'module' => 'Configuración', 'description' => 'Administrar administradores y roles del personal (solo ROOT)'])->syncRoles([$rootRole]);

        /** PANEL ADMINISTRATION (administration-panel) */
        // updateOrCreate here mirrors the ROOT_ADMINISTRATION role above: this grant must be
        // safe to re-run without creating duplicate permission rows or duplicate role_has_permissions
        // pivot rows (syncRoles is idempotent by nature — it replaces the role set, not append),
        // and must retag an already-existing row (type='USER' default) to type='ADMINISTRATION'
        // (control-accesos-administration-panel PR1, B0 retag, design obs #1593).
        // The description/module copy rides the same updateOrCreate update-path, so re-applying
        // these lines retroactively populates already-seeded rows (permission-descriptions-modules,
        // design obs #1601).
        Permission::updateOrCreate(['name' => 'ADM_READ_USERS'], ['type' => 'ADMINISTRATION', 'module' => 'Usuarios', 'description' => 'Ver la lista de becarios y egresados'])->syncRoles([$rootAdministrationRole]);
        // Payment data (becarios-payment-config, design D5/D9): read/write split
        // granted to ROOT_ADMINISTRATION ONLY (confirmed by user, not ROOT).
        // updateOrCreate keeps these two lines safe to re-apply in isolation via
        // tinker on a non-fresh DB, and retags pre-existing rows, same as ADM_READ_USERS above.
        Permission::updateOrCreate(['name' => 'ADM_READ_PAYMENT_DATA'], ['type' => 'ADMINISTRATION', 'module' => 'Datos de pago', 'description' => 'Ver los datos de pago de un becario'])->syncRoles([$rootAdministrationRole]);
        Permission::updateOrCreate(['name' => 'ADM_EDIT_PAYMENT_DATA'], ['type' => 'ADMINISTRATION', 'module' => 'Datos de pago', 'description' => 'Editar los datos de pago de un becario'])->syncRoles([$rootAdministrationRole]);
        // Scholarship profile config (scholarship-profile-config-to-admin,
        // design D9): NO read-gate — the GET route is shared infrastructure
        // with psicol-panel (see design D9 rationale). Granted only to
        // ROOT_ADMINISTRATION, same updateOrCreate rationale as above.
        Permission::updateOrCreate(['name' => 'ADM_EDIT_SCHOLARSHIP_PROFILE'], ['type' => 'ADMINISTRATION', 'module' => 'Datos de pago', 'description' => 'Editar la configuración de beca de un becario'])->syncRoles([$rootAdministrationRole]);
        // Control (Roles + Accesos), control-accesos-administration-panel PR2
        // (design obs #1593, tasks obs #1594 Phase 2 note): these 4 were
        // originally planned for PR1 but deferred here, since the
        // controllers/routes that require them are introduced in this PR
        // (ADM_READ_ROLES/ADM_MANAGE_ROLES) and PR8+ (ADM_READ_ADMINS/
        // ADM_MANAGE_ADMINS — seeded now, consumed later). Granted only to
        // ROOT_ADMINISTRATION, same updateOrCreate rationale as above.
        Permission::updateOrCreate(['name' => 'ADM_READ_ROLES'], ['type' => 'ADMINISTRATION', 'module' => 'Roles', 'description' => 'Ver la lista de roles y sus permisos'])->syncRoles([$rootAdministrationRole]);
        Permission::updateOrCreate(['name' => 'ADM_MANAGE_ROLES'], ['type' => 'ADMINISTRATION', 'module' => 'Roles', 'description' => 'Crear roles y editar sus permisos'])->syncRoles([$rootAdministrationRole]);
        Permission::updateOrCreate(['name' => 'ADM_READ_ADMINS'], ['type' => 'ADMINISTRATION', 'module' => 'Accesos', 'description' => 'Ver la lista de administradores'])->syncRoles([$rootAdministrationRole]);
        Permission::updateOrCreate(['name' => 'ADM_MANAGE_ADMINS'], ['type' => 'ADMINISTRATION', 'module' => 'Accesos', 'description' => 'Crear administradores y asignarles un rol'])->syncRoles([$rootAdministrationRole]);
        // Pagos (becario-payment-file-generation PR1, design "New Permissions"):
        // batch review + all-or-nothing payment processing. Two separately
        // grantable capabilities, granted only to ROOT_ADMINISTRATION, same
        // updateOrCreate rationale as above (safe to re-apply in isolation
        // via tinker on a non-fresh DB, retags a pre-existing row).
        Permission::updateOrCreate(['name' => 'ADM_READ_PAYMENTS'], ['type' => 'ADMINISTRATION', 'module' => 'Pagos', 'description' => 'Ver el listado de pagos y el documento de pago de un becario'])->syncRoles([$rootAdministrationRole]);
        Permission::updateOrCreate(['name' => 'ADM_PROCESS_PAYMENTS'], ['type' => 'ADMINISTRATION', 'module' => 'Pagos', 'description' => 'Procesar el pago de un grupo de becarios'])->syncRoles([$rootAdministrationRole]);
        // Bank-file export (becario-payment-bank-file-export PR4, design "New
        // Permissions"): downloading the BBVA dispersión file for an already
        // paid batch, distinct from ADM_READ_PAYMENTS/ADM_PROCESS_PAYMENTS.
        // Granted only to ROOT_ADMINISTRATION, same updateOrCreate rationale
        // as above (safe to re-apply in isolation via tinker on a non-fresh
        // DB, retags a pre-existing row). syncRoles is correct here — this is
        // a brand-new permission with no prior role_has_permissions rows, so
        // syncing only affects THIS permission's role list, not any other
        // permission any role already holds (no stripping risk).
        Permission::updateOrCreate(['name' => 'ADM_EXPORT_PAYMENTS'], ['type' => 'ADMINISTRATION', 'module' => 'Pagos', 'description' => 'Descargar el archivo bancario de dispersión de un lote de pago procesado'])->syncRoles([$rootAdministrationRole]);
        // Telmex settings (sdd/scholarship-telmex-iu-split, design D8): the
        // GET endpoint has NO permission gate (shared reference data), so
        // only the write capability is seeded. A NEW permission, not
        // ADM_EDIT_SCHOLARSHIP_PROFILE — that one is scoped to a single
        // becario's profile (module "Datos de pago"); this is an org-wide
        // yearly reference value with a different blast radius. Granted only
        // to ROOT_ADMINISTRATION, same updateOrCreate rationale as above.
        Permission::updateOrCreate(['name' => 'ADM_MANAGE_SCHOLARSHIP_SETTINGS'], ['type' => 'ADMINISTRATION', 'module' => 'Configuración de becas', 'description' => 'Editar el monto de referencia global de Telmex'])->syncRoles([$rootAdministrationRole]);
        // Becas Telmex (sdd/telmex-cobertura-iu, design API table + tasks
        // 3b.4): read/manage split mirrors ADM_READ_PAYMENTS/ADM_PROCESS_PAYMENTS
        // above, plus a third, separately-grantable capability for
        // repayments (register/void abonos) per spec's independent
        // permission requirement. Granted only to ROOT_ADMINISTRATION, same
        // updateOrCreate rationale as every other ADM_* entry above (safe to
        // re-apply in isolation via tinker on a non-fresh DB, retags a
        // pre-existing row).
        Permission::updateOrCreate(['name' => 'ADM_READ_TELMEX_COVERAGE'], ['type' => 'ADMINISTRATION', 'module' => 'Becas Telmex', 'description' => 'Ver el listado y detalle de coberturas Telmex'])->syncRoles([$rootAdministrationRole]);
        Permission::updateOrCreate(['name' => 'ADM_MANAGE_TELMEX_COVERAGE'], ['type' => 'ADMINISTRATION', 'module' => 'Becas Telmex', 'description' => 'Activar, finalizar, cancelar y reactivar una cobertura Telmex'])->syncRoles([$rootAdministrationRole]);
        Permission::updateOrCreate(['name' => 'ADM_MANAGE_TELMEX_REPAYMENTS'], ['type' => 'ADMINISTRATION', 'module' => 'Becas Telmex', 'description' => 'Registrar y revertir abonos de una cobertura Telmex'])->syncRoles([$rootAdministrationRole]);

        // sdd/catalogo-bancos PR1: Control > Bancos catalog. Read/manage split,
        // granted only to ROOT_ADMINISTRATION, same updateOrCreate rationale as above.
        Permission::updateOrCreate(['name' => 'ADM_READ_BANKS'], ['type' => 'ADMINISTRATION', 'module' => 'Bancos', 'description' => 'Ver el catálogo de bancos'])->syncRoles([$rootAdministrationRole]);
        Permission::updateOrCreate(['name' => 'ADM_MANAGE_BANKS'], ['type' => 'ADMINISTRATION', 'module' => 'Bancos', 'description' => 'Crear, editar, desactivar y eliminar bancos del catálogo'])->syncRoles([$rootAdministrationRole]);
    }
}
