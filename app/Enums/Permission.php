<?php

namespace App\Enums;

enum Permission: string
{
    case DashboardView = 'dashboard.view';

    case ProjectsView = 'projects.view';
    case ProjectsCreate = 'projects.create';
    case ProjectsUpdate = 'projects.update';
    case ProjectsDelete = 'projects.delete';
    case ProjectsArchive = 'projects.archive';
    case ProgressUpdate = 'progress.update';
    case ProgressApprove = 'progress.approve';
    case FilesView = 'files.view';
    case FilesUpload = 'files.upload';
    case RevisionsProcess = 'revisions.process';

    case PlanningView = 'planning.view';
    case PlanningUpdate = 'planning.update';
    case PlanningAssign = 'planning.assign';
    case PlanningDrag = 'planning.drag';
    case PlanningHours = 'planning.hours';
    case PlanningAbsence = 'planning.absence';
    case PlanningWeekPdf = 'planning.week_pdf';
    case PersonnelWeekView = 'personnel_week.view';
    case HoursView = 'hours.view';
    case HoursApprove = 'hours.approve';

    case CalculationsView = 'calculations.view';
    case CalculationsCreate = 'calculations.create';
    case CalculationsUpdate = 'calculations.update';
    case CalculationsUpload = 'calculations.upload';
    case CalculationsStatus = 'calculations.status';
    case CalculationsMaterials = 'calculations.materials';
    case CalculationsDelete = 'calculations.delete';
    case CalculationsPrint = 'calculations.print';

    case DrawingsView = 'drawings.view';
    case MeetstaatView = 'meetstaat.view';
    case MaterialsView = 'materials.view';
    case MaterialsUpdate = 'materials.update';

    case SnagsView = 'snags.view';
    case SnagsCreate = 'snags.create';
    case SnagsUpdate = 'snags.update';
    case SnagsDelete = 'snags.delete';
    case SnagsReport = 'snags.report';
    case SnagsClose = 'snags.close';
    case SnagsReject = 'snags.reject';

    case WorkTicketsView = 'work_tickets.view';
    case WorkTicketsCreate = 'work_tickets.create';
    case WorkTicketsUpdate = 'work_tickets.update';
    case WorkTicketsComplete = 'work_tickets.complete';
    case WorkTicketsPdf = 'work_tickets.pdf';

    case VouchersView = 'vouchers.view';
    case VouchersCreate = 'vouchers.create';
    case VouchersUpdate = 'vouchers.update';
    case VouchersApprove = 'vouchers.approve';
    case VouchersPdf = 'vouchers.pdf';

    case WorkersView = 'workers.view';
    case WorkersCreate = 'workers.create';
    case WorkersUpdate = 'workers.update';
    case WorkersDelete = 'workers.delete';

    case TeamsView = 'teams.view';
    case TeamsUpdate = 'teams.update';

    case ReportsView = 'reports.view';
    case LaborCostsView = 'labor_costs.view';

    case UsersView = 'users.view';
    case UsersManage = 'users.manage';

    case CatalogView = 'catalog.view';
    case CatalogManage = 'catalog.manage';

    public function isRead(): bool
    {
        $value = $this->value;

        return str_ends_with($value, '.view')
            || str_ends_with($value, '.pdf')
            || str_ends_with($value, '.print')
            || $this === self::PlanningWeekPdf;
    }

    public function grantedToReadOnly(): bool
    {
        if (! $this->isRead()) {
            return false;
        }

        return ! in_array($this, [self::UsersView, self::CatalogView], true);
    }

    public function viewPermission(): self
    {
        return match ($this) {
            self::ProjectsArchive, self::ProjectsDelete => self::ProjectsView,
            self::ProgressUpdate, self::ProgressApprove => self::ProjectsView,
            self::FilesUpload, self::RevisionsProcess => self::FilesView,
            self::PlanningUpdate, self::PlanningAssign, self::PlanningDrag,
            self::PlanningHours, self::PlanningAbsence, self::PlanningWeekPdf => self::PlanningView,
            self::HoursApprove => self::HoursView,
            self::CalculationsPrint => self::CalculationsView,
            self::MaterialsUpdate => self::MaterialsView,
            self::WorkTicketsPdf => self::WorkTicketsView,
            self::VouchersPdf, self::VouchersApprove => self::VouchersView,
            self::UsersManage => self::UsersView,
            self::CatalogManage => self::CatalogView,
            default => self::tryFrom(explode('.', $this->value)[0].'.view') ?? $this,
        };
    }
}
