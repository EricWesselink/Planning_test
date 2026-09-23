<?php

namespace Tests\Unit;

use App\Services\CalculationWorkMatcher;
use App\Services\RoomWorkSetup;
use Tests\TestCase;

class CalculationWorkMatcherTest extends TestCase
{
    public function test_maps_preparation_pvc_carpet_and_plinths_to_existing_work_types(): void
    {
        $matcher = app(CalculationWorkMatcher::class);

        $prep = $matcher->match('Schuren, primeren en egaliseren max. 2 mm', ['PVC', RoomWorkSetup::PRIMEN_EGALISEREN]);
        $this->assertSame('matched', $prep['status']);
        $this->assertSame(RoomWorkSetup::PRIMEN_EGALISEREN, $prep['work_name']);

        $pvc = $matcher->match('Leveren en leggen IVC Ultimo Trasimeno 46906, PVC tegels', ['PVC', 'Tapijt']);
        $this->assertSame('matched', $pvc['status']);
        $this->assertSame('PVC', $pvc['work_name']);

        $carpet = $matcher->match('Leveren en leggen tapijttegels Desso Airmaster', ['PVC', 'Tapijt']);
        $this->assertSame('matched', $carpet['status']);
        $this->assertSame('Tapijt', $carpet['work_name']);

        $plinth = $matcher->match('Leveren en aanbrengen hardschuimplinten 60/15', ['Plinten']);
        $this->assertSame('matched', $plinth['status']);
        $this->assertSame('Plinten', $plinth['work_name']);

        $profiles = $matcher->match('Overgangsprofielen tussen verschillende vloerafwerkingen', [], [
            'materials' => [
                ['label' => 'overgangsprofielen'],
            ],
        ]);
        $this->assertSame('matched', $profiles['status']);
        $this->assertSame('Overgangsprofielen', $profiles['work_name']);
    }

    public function test_maps_generic_elastic_covering_from_neighboring_marmoleum_material(): void
    {
        $matched = app(CalculationWorkMatcher::class)->match('Elastische vloerbedekking', [
            'Marmoleum Real, 3120 rosato, Linoleum',
            'Marmoleum Walton, 3355 rosemary green, Linoleum',
            'PU gietvloer kleur n.t.b., Coating',
        ], [
            'materials' => [
                ['label' => 'Marmoleum Real 3120 rosato', 'm2' => 2226.69, 'm1' => 0.0],
            ],
        ]);

        $this->assertSame('matched', $matched['status']);
        $this->assertSame('Marmoleum Real, 3120 rosato, Linoleum', $matched['work_name']);
    }

    public function test_maps_generic_soft_covering_to_entrance_mat_or_carpet_from_article(): void
    {
        $matcher = app(CalculationWorkMatcher::class);

        $mat = $matcher->match('Zachte vloerbedekking', [
            'Coral Welcome, 3202 desperado, Entreemat',
            'Desso Airmaster, Tapijt',
        ], [
            'materials' => [['label' => 'Coral Welcome 3202 desperado', 'm2' => 12.4, 'm1' => 0.0]],
        ]);
        $this->assertSame('matched', $mat['status']);
        $this->assertSame('Coral Welcome, 3202 desperado, Entreemat', $mat['work_name']);

        $carpet = $matcher->match('Zachte vloerbedekking', [
            'Coral Welcome, 3202 desperado, Entreemat',
            'Desso Airmaster, Tapijt',
        ], [
            'materials' => [['label' => 'Desso Airmaster tapijttegels', 'm2' => 80.0, 'm1' => 0.0]],
        ]);
        $this->assertSame('matched', $carpet['status']);
        $this->assertSame('Desso Airmaster, Tapijt', $carpet['work_name']);
    }

    public function test_maps_generic_elastic_covering_to_pvc_or_resin_from_neighboring_article(): void
    {
        $matcher = app(CalculationWorkMatcher::class);

        $pvc = $matcher->match('Elastische vloerbedekking', ['PVC', 'Gietvloer'], [
            'materials' => [['label' => 'IVC Ultimo Trasimeno 46906 PVC tegels', 'm2' => 178.2, 'm1' => 0.0]],
        ]);
        $this->assertSame('matched', $pvc['status']);
        $this->assertSame('PVC', $pvc['work_name']);

        $resin = $matcher->match('Elastische vloerbedekking', [
            'Marmoleum Real, 3120 rosato, Linoleum',
            'PU gietvloer kleur n.t.b., Coating',
        ], [
            'materials' => [['label' => 'PU gietvloer kleur n.t.b.', 'm2' => 48.0, 'm1' => 0.0]],
        ]);
        $this->assertSame('matched', $resin['status']);
        $this->assertSame('PU gietvloer kleur n.t.b., Coating', $resin['work_name']);
    }

    public function test_keeps_excel_gietvloer_and_coating_on_separate_works(): void
    {
        $matcher = app(CalculationWorkMatcher::class);
        $works = [
            'PU gietvloer, Ral 7039 met vlok, Coating',
            'vloercoating op CD vloer, Coating',
        ];

        $gietvloer = $matcher->match('PU gietvloer in RAL 7039 (eventueel voorzien va inkoop Amipox)', $works);
        $this->assertSame('matched', $gietvloer['status']);
        $this->assertSame('PU gietvloer, Ral 7039 met vlok, Coating', $gietvloer['work_name']);

        $coating = $matcher->match('Lijvige Epoxy vloercoating inkoop Amipox', $works);
        $this->assertSame('matched', $coating['status']);
        $this->assertSame('vloercoating op CD vloer, Coating', $coating['work_name']);
    }

    public function test_maps_surcharge_rows_to_overige_instead_of_blocking_import(): void
    {
        $matcher = app(CalculationWorkMatcher::class);

        $surcharge = $matcher->match('Toeslag leggen proefkamer', ['PVC']);
        $this->assertSame('matched', $surcharge['status']);
        $this->assertSame('Overige', $surcharge['work_name']);

        $storage = $matcher->match('Kosten besteld pvc voor het souterrain, en in opslag nemen', ['PVC']);
        $this->assertSame('warning', $storage['status']);
        $this->assertSame('PVC', $storage['work_name']);
    }

    public function test_asks_for_review_when_no_work_type_is_reliable(): void
    {
        $matched = app(CalculationWorkMatcher::class)->match('Onbekende extra werkzaamheid xyz', ['PVC']);

        $this->assertSame('review', $matched['status']);
        $this->assertNull($matched['work_name']);
    }

    public function test_does_not_send_generic_covering_to_review_when_one_compatible_work_exists(): void
    {
        $matched = app(CalculationWorkMatcher::class)->match('Elastische vloerbedekking', [
            'Marmoleum Real, 3120 rosato, Linoleum',
        ]);

        $this->assertSame('matched', $matched['status']);
        $this->assertSame('Marmoleum Real, 3120 rosato, Linoleum', $matched['work_name']);
    }
}
