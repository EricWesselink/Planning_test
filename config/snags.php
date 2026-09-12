<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Public ZZP link lifetime
    |--------------------------------------------------------------------------
    |
    | New and reissued public snag links expire after this many days. Existing
    | rows with a null expiry stay valid until they are revoked or reissued.
    |
    */

    'public_token_ttl_days' => (int) env('SNAG_PUBLIC_TOKEN_TTL_DAYS', 30),

];
