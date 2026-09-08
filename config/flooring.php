<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Vloerwerk-categorieën
    |--------------------------------------------------------------------------
    |
    | requires_priming_leveling: egaliseren onder harde vloerafwerkingen
    | (linoleum, PVC, vinyl). Niet onder tapijt, tapijttegels,
    | schoonloopmat/entreemat, coating, gietvloer of plinten.
    | Projecttotaal volgt de PVC/linoleum/vinyl-werkitems, ook zonder ruimtetaken.
    |
    */
    'categories' => [
        'Linoleum' => [
            'requires_priming_leveling' => true,
        ],
        'PVC' => [
            'requires_priming_leveling' => true,
        ],
        'Vinyl' => [
            'requires_priming_leveling' => true,
        ],
        'Gietvloer' => [
            'requires_priming_leveling' => false,
        ],
        'Coating' => [
            'requires_priming_leveling' => false,
        ],
        'Tapijt' => [
            'requires_priming_leveling' => false,
        ],
        'Entreemat' => [
            'requires_priming_leveling' => false,
        ],
        'Plinten' => [
            'requires_priming_leveling' => false,
        ],
    ],

    'priming_leveling' => [
        'work_name' => 'Primen & Egaliseren',
        'source_label' => 'afgeleid van vloerafwerking',
    ],

];
