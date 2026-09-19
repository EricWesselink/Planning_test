<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Planner = 'planner';
    case Uitvoerder = 'uitvoerder';
    case Projectleider = 'projectleider';
    case AlleenLezen = 'alleen_lezen';
    case Aangepast = 'aangepast';
    case Vakman = 'vakman';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Beheerder',
            self::Planner => 'Planner',
            self::Uitvoerder => 'Uitvoerder',
            self::Projectleider => 'Projectleider',
            self::AlleenLezen => 'Alleen lezen',
            self::Aangepast => 'Aangepaste rechten',
            self::Vakman => 'Vakman',
        };
    }

    public function usesPermissionMatrix(): bool
    {
        return $this === self::AlleenLezen || $this === self::Aangepast;
    }

    /**
     * @return list<self>
     */
    public static function officeStandardCases(): array
    {
        return [self::Admin, self::Planner, self::Uitvoerder, self::Projectleider];
    }

    public function canManageUsers(): bool
    {
        return $this === self::Admin;
    }

    public function canManageCatalog(): bool
    {
        return $this === self::Admin;
    }

    public function canPurgeProjects(): bool
    {
        return $this === self::Admin;
    }

    public function canArchiveProjects(): bool
    {
        return $this->is(self::Admin, self::Projectleider);
    }

    public function canManageProjects(): bool
    {
        return $this->is(self::Admin, self::Projectleider, self::Planner);
    }

    public function canManagePlanning(): bool
    {
        return $this->is(self::Admin, self::Projectleider, self::Planner);
    }

    public function canViewLaborCosts(): bool
    {
        return $this->canManagePlanning();
    }

    public function canManageWorkers(): bool
    {
        return $this->is(self::Admin, self::Planner);
    }

    public function canEnterProgress(): bool
    {
        return $this->is(self::Admin, self::Projectleider, self::Uitvoerder, self::Vakman);
    }

    public function canApproveProgress(): bool
    {
        return $this->is(self::Admin, self::Projectleider);
    }

    public function canCreateSnags(): bool
    {
        return $this->is(self::Admin, self::Projectleider, self::Uitvoerder);
    }

    public function canUpdateSnags(): bool
    {
        return true;
    }

    public function canAdvanceSnagStatus(): bool
    {
        return $this->is(self::Admin, self::Projectleider, self::Uitvoerder);
    }

    public function canReportSnags(): bool
    {
        return $this->is(self::Admin, self::Projectleider, self::Uitvoerder);
    }

    public function canCloseSnags(): bool
    {
        return $this->is(self::Admin, self::Projectleider);
    }

    public function canManuallyLinkRooms(): bool
    {
        return $this === self::Admin;
    }

    public function canRejectSnags(): bool
    {
        return $this->is(self::Admin, self::Projectleider, self::Uitvoerder);
    }

    public function canDeleteSnags(): bool
    {
        return $this->is(self::Admin, self::Uitvoerder, self::Projectleider);
    }

    private function is(self ...$roles): bool
    {
        return in_array($this, $roles, true);
    }
}
