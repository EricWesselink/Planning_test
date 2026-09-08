<?php

namespace Tests\Unit;

use App\Support\RoomUniqueName;
use Tests\TestCase;

class RoomUniqueNameTest extends TestCase
{
    public function test_duplicate_names_become_numbered_labels_in_list_order(): void
    {
        $areas = RoomUniqueName::assign([
            ['id' => 12, 'name' => 'gang', 'number' => '', 'floor' => 'begane grond', 'floor_sort' => 1],
            ['id' => 8, 'name' => 'Gang', 'number' => '', 'floor' => 'begane grond', 'floor_sort' => 1],
            ['id' => 9, 'name' => 'theorielokaal', 'number' => 'P.0.17', 'floor' => 'begane grond', 'floor_sort' => 1],
            ['id' => 10, 'name' => 'entree 4', 'number' => '', 'floor' => 'begane grond', 'floor_sort' => 1],
            ['id' => 11, 'name' => 'entree 4', 'number' => '', 'floor' => 'begane grond', 'floor_sort' => 1],
        ]);

        $byId = collect($areas)->keyBy('id');

        $this->assertSame('Gang 1', $byId[8]['unique_name']);
        $this->assertSame('Gang 2', $byId[12]['unique_name']);
        $this->assertSame('theorielokaal', $byId[9]['unique_name']);
        $this->assertSame('Entree 4 1', $byId[10]['unique_name']);
        $this->assertSame('Entree 4 2', $byId[11]['unique_name']);
        $this->assertSame('gang', $byId[12]['name']);
        $this->assertSame('Gang', $byId[8]['name']);
    }

    public function test_unique_names_stay_unnumbered(): void
    {
        $areas = RoomUniqueName::assign([
            ['id' => 1, 'name' => 'gang', 'number' => '', 'floor' => 'begane grond', 'floor_sort' => 1],
            ['id' => 2, 'name' => 'aula', 'number' => '0.01', 'floor' => 'begane grond', 'floor_sort' => 1],
        ]);

        $this->assertSame('gang', $areas[0]['unique_name']);
        $this->assertSame('aula', $areas[1]['unique_name']);
    }

    public function test_empty_duplicate_names_use_ruimte_prefix(): void
    {
        $areas = RoomUniqueName::assign([
            ['id' => 2, 'name' => '', 'number' => '', 'floor' => 'begane grond', 'floor_sort' => 1],
            ['id' => 1, 'name' => '  ', 'number' => '', 'floor' => 'begane grond', 'floor_sort' => 1],
        ]);

        $byId = collect($areas)->keyBy('id');

        $this->assertSame('Ruimte 1', $byId[1]['unique_name']);
        $this->assertSame('Ruimte 2', $byId[2]['unique_name']);
    }
}
