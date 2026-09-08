<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\DrawingRoomRelinker;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

#[Signature('drawings:relink {--project= : Project-id, nummer of naamfragment} {--all : Alle projecten met een opgeslagen plattegrond}')]
#[Description('Koppel ruimtes opnieuw aan de opgeslagen plattegrond-PDF')]
class RelinkDrawingRoomsCommand extends Command
{
    public function handle(DrawingRoomRelinker $relinker): int
    {
        $projects = $this->projects();
        if ($projects->isEmpty()) {
            $this->error('Geen project met plattegrond gevonden.');

            return self::FAILURE;
        }

        $totals = ['auto' => 0, 'manual' => 0, 'none' => 0];
        foreach ($projects as $project) {
            $report = $relinker->relink($project);
            $this->printReport($report);
            $totals['auto'] += (int) ($report['counts']['auto'] ?? 0);
            $totals['manual'] += (int) ($report['counts']['manual'] ?? 0);
            $totals['none'] += (int) ($report['counts']['none'] ?? $report['counts']['unmatched'] ?? 0);
        }

        if ($projects->count() > 1) {
            $this->newLine();
            $this->info('Totaal');
            $this->printCounts($totals);
        }

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Project>
     */
    private function projects()
    {
        $query = Project::query()
            ->with(['documents', 'areas.floor', 'areas.markers'])
            ->whereHas('documents', fn ($documents) => $documents->where('document_type', 'plattegrond'));

        $needle = trim((string) $this->option('project'));
        if ($needle !== '') {
            $query->where(function ($inner) use ($needle) {
                if (ctype_digit($needle)) {
                    $inner->orWhere('id', (int) $needle);
                }
                $inner->orWhere('project_number', $needle)
                    ->orWhere('name', 'like', '%'.$needle.'%');
            });
        } elseif (! $this->option('all')) {
            $query->where('name', 'like', '%Sluisbuurt%');
        }

        return $query->orderBy('id')->get();
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function printReport(array $report): void
    {
        $this->newLine();
        $this->info($report['project'].' (#'.$report['project_id'].')');
        if (filled($report['error'] ?? null)) {
            $this->warn((string) $report['error']);
        }
        $this->printCounts($report['counts']);
        $none = $report['none'] ?? $report['unmatched'] ?? [];
        $noneCount = (int) ($report['counts']['none'] ?? $report['counts']['unmatched'] ?? count($none));
        if ($noneCount > 0 && $noneCount <= 20) {
            $this->comment('  Geen kandidaat: '.implode(', ', $none));
        }
    }

    /**
     * @param  array{auto?: int, manual?: int, none?: int}  $counts
     */
    private function printCounts(array $counts): void
    {
        $this->line('Automatisch gekoppeld: '.($counts['auto'] ?? 0));
        $this->line('Handmatig nodig: '.($counts['manual'] ?? 0));
    }
}
