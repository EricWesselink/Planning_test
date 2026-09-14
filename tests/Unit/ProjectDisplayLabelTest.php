<?php

namespace Tests\Unit;

use App\Models\Project;
use Tests\TestCase;

class ProjectDisplayLabelTest extends TestCase
{
    public function test_labeled_display_splits_projectnr_werk_and_title(): void
    {
        $project = new Project([
            'project_number' => '250200015',
            'name' => '11P230988 TWC studentenhuisvesting Utrecht',
            'notes' => 'Referentie: 11P230988 TWC studentenhuisvesting Utrecht',
        ]);

        $this->assertSame('250200015', $project->workNumber());
        $this->assertSame('11P230988', $project->workCode());
        $this->assertSame('TWC studentenhuisvesting Utrecht', $project->displayTitle());
        $this->assertSame("Projectnr.\u{00A0}11P230988\u{00A0}·\u{00A0}Werk\u{00A0}250200015", $project->labeledNumbersLine());
        $this->assertSame('11P230988 TWC studentenhuisvesting Utrecht', $project->reference());
    }

    public function test_display_helpers_fall_back_without_werk_code(): void
    {
        $project = new Project([
            'project_number' => '2024-118',
            'name' => 'TMZ Meubelenbelt Fase 2',
            'notes' => null,
        ]);

        $this->assertNull($project->workCode());
        $this->assertSame('TMZ Meubelenbelt Fase 2', $project->displayTitle());
        $this->assertSame("Werk\u{00A0}2024-118", $project->labeledNumbersLine());
    }

    public function test_apply_work_code_sets_projectnr_without_changing_the_work_number(): void
    {
        $project = new Project([
            'project_number' => '2026-001',
            'name' => 'Zeewolde, Bouw Havenkwartier Zuyd',
            'notes' => null,
        ]);

        $project->applyWorkCode('11p260521');

        $this->assertSame('2026-001', $project->workNumber());
        $this->assertSame('11P260521', $project->workCode());
        $this->assertSame('Zeewolde, Bouw Havenkwartier Zuyd', $project->displayTitle());
        $this->assertSame("Projectnr.\u{00A0}11P260521\u{00A0}·\u{00A0}Werk\u{00A0}2026-001", $project->labeledNumbersLine());
    }
}
