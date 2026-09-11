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
    }

    public function test_marks_surcharge_and_storage_cost_rows_for_review(): void
    {
        $matcher = app(CalculationWorkMatcher::class);

        $surcharge = $matcher->match('Toeslag leggen proefkamer', ['PVC']);
        $this->assertSame('review', $surcharge['status']);

        $storage = $matcher->match('Kosten besteld pvc voor het souterrain, en in opslag nemen', ['PVC']);
        $this->assertSame('review', $storage['status']);
    }

    public function test_asks_for_review_when_no_work_type_is_reliable(): void
    {
        $matched = app(CalculationWorkMatcher::class)->match('Onbekende extra werkzaamheid xyz', ['PVC']);

        $this->assertSame('review', $matched['status']);
        $this->assertNull($matched['work_name']);
    }
}
