<?php

namespace App\Support;

use App\Enums\Permission;

class PermissionCatalog
{
    /**
     * @return list<array{key: string, label: string, permissions: list<array{permission: Permission, label: string, kind: string}>}>
     */
    public static function groups(): array
    {
        return [
            self::group('dashboard', 'Dashboard', [
                [Permission::DashboardView, 'Zien', 'view'],
            ]),
            self::group('projects', 'Projecten', [
                [Permission::ProjectsView, 'Zien', 'view'],
                [Permission::ProjectsCreate, 'Toevoegen', 'create'],
                [Permission::ProjectsUpdate, 'Wijzigen', 'update'],
                [Permission::ProjectsDelete, 'Verwijderen', 'delete'],
                [Permission::ProjectsArchive, 'Archiveren', 'extra'],
                [Permission::ProgressUpdate, 'Voortgang wijzigen', 'extra'],
                [Permission::ProgressApprove, 'Voortgang goedkeuren', 'extra'],
                [Permission::FilesView, 'Bestanden bekijken', 'extra'],
                [Permission::FilesUpload, 'Bestanden uploaden', 'extra'],
                [Permission::RevisionsProcess, 'Revisies verwerken', 'extra'],
            ]),
            self::group('planning', 'Planning', [
                [Permission::PlanningView, 'Planning bekijken', 'view'],
                [Permission::PlanningUpdate, 'Planning aanpassen', 'update'],
                [Permission::PlanningAssign, 'Vakmannen inplannen', 'extra'],
                [Permission::PlanningDrag, 'Planning verslepen', 'extra'],
                [Permission::PlanningHours, 'Uren aanpassen', 'extra'],
                [Permission::PlanningAbsence, 'Afwezigheid aanpassen', 'extra'],
                [Permission::PlanningWeekPdf, 'Weekplanning PDF downloaden', 'extra'],
                [Permission::PersonnelWeekView, 'Weekplanning personeel', 'view'],
                [Permission::LaborCostsView, 'Uren en kosten zien', 'view'],
            ]),
            self::group('calculations', 'Calculaties', [
                [Permission::CalculationsView, 'Bekijken', 'view'],
                [Permission::CalculationsCreate, 'Nieuwe calculatie', 'create'],
                [Permission::CalculationsUpdate, 'Wijzigen', 'update'],
                [Permission::CalculationsUpload, 'PDF/Excel uploaden', 'extra'],
                [Permission::CalculationsStatus, 'Controle/status aanpassen', 'extra'],
                [Permission::CalculationsMaterials, 'Materiaalkeuze aanpassen', 'extra'],
                [Permission::CalculationsDelete, 'Calculatie verwijderen', 'delete'],
                [Permission::CalculationsPrint, 'Print/PDF', 'extra'],
            ]),
            self::group('drawings', 'Tekeningen', [
                [Permission::DrawingsView, 'Zien', 'view'],
            ]),
            self::group('meetstaat', 'Meetstaten', [
                [Permission::MeetstaatView, 'Zien', 'view'],
            ]),
            self::group('materials', 'Materialen', [
                [Permission::MaterialsView, 'Zien', 'view'],
                [Permission::MaterialsUpdate, 'Wijzigen', 'update'],
            ]),
            self::group('snags', 'Opleverpunten', [
                [Permission::SnagsView, 'Zien', 'view'],
                [Permission::SnagsCreate, 'Toevoegen', 'create'],
                [Permission::SnagsUpdate, 'Wijzigen', 'update'],
                [Permission::SnagsDelete, 'Verwijderen', 'delete'],
                [Permission::SnagsReport, 'Gereed melden', 'extra'],
                [Permission::SnagsClose, 'Afsluiten', 'extra'],
                [Permission::SnagsReject, 'Afkeuren', 'extra'],
            ]),
            self::group('work_tickets', 'Werkbonnen', [
                [Permission::WorkTicketsView, 'Bekijken', 'view'],
                [Permission::WorkTicketsCreate, 'Aanmaken', 'create'],
                [Permission::WorkTicketsUpdate, 'Wijzigen', 'update'],
                [Permission::WorkTicketsComplete, 'Afronden', 'extra'],
                [Permission::WorkTicketsPdf, 'PDF downloaden', 'extra'],
            ]),
            self::group('vouchers', 'Opdrachtbonnen', [
                [Permission::VouchersView, 'Bekijken', 'view'],
                [Permission::VouchersCreate, 'Aanmaken', 'create'],
                [Permission::VouchersUpdate, 'Wijzigen', 'update'],
                [Permission::VouchersApprove, 'Goedkeuren/afronden', 'extra'],
                [Permission::VouchersPdf, 'PDF downloaden', 'extra'],
            ]),
            self::group('workers', 'Vakmensen', [
                [Permission::WorkersView, 'Zien', 'view'],
                [Permission::WorkersCreate, 'Toevoegen', 'create'],
                [Permission::WorkersUpdate, 'Wijzigen', 'update'],
                [Permission::WorkersDelete, 'Verwijderen', 'delete'],
            ]),
            self::group('teams', 'Teams', [
                [Permission::TeamsView, 'Zien', 'view'],
                [Permission::TeamsUpdate, 'Wijzigen', 'update'],
            ]),
            self::group('reports', 'Rapportages / PDF', [
                [Permission::ReportsView, 'Zien', 'view'],
            ]),
            self::group('users', 'Gebruikers / rechten', [
                [Permission::UsersView, 'Zien', 'view'],
                [Permission::UsersManage, 'Beheren', 'update'],
            ]),
            self::group('catalog', 'Werkzaamheden', [
                [Permission::CatalogView, 'Zien', 'view'],
                [Permission::CatalogManage, 'Wijzigen', 'update'],
            ]),
        ];
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    public static function sanitize(array $values): array
    {
        $valid = [];
        foreach ($values as $value) {
            if (! is_string($value)) {
                continue;
            }

            $permission = Permission::tryFrom($value);
            if ($permission === null) {
                continue;
            }

            $valid[$permission->value] = $permission;
            $view = $permission->viewPermission();
            $valid[$view->value] = $view;
        }

        $keys = array_keys($valid);
        sort($keys);

        return $keys;
    }

    /**
     * @param  list<string>|null  $values
     */
    public static function hasWrite(?array $values): bool
    {
        foreach ($values ?? [] as $value) {
            $permission = Permission::tryFrom((string) $value);
            if ($permission !== null && ! $permission->isRead()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public static function viewPreset(): array
    {
        $keys = [];
        foreach (Permission::cases() as $permission) {
            if ($permission->grantedToReadOnly()) {
                $keys[] = $permission->value;
            }
        }

        sort($keys);

        return $keys;
    }

    /**
     * @return list<string>
     */
    public static function allPreset(): array
    {
        $keys = array_map(fn (Permission $permission) => $permission->value, Permission::cases());
        sort($keys);

        return $keys;
    }

    /**
     * @param  list<array{0: Permission, 1: string, 2: string}>  $permissions
     * @return array{key: string, label: string, permissions: list<array{permission: Permission, label: string, kind: string}>}
     */
    private static function group(string $key, string $label, array $permissions): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'permissions' => array_map(
                fn (array $row): array => [
                    'permission' => $row[0],
                    'label' => $row[1],
                    'kind' => $row[2],
                ],
                $permissions,
            ),
        ];
    }
}
