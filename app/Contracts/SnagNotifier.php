<?php

namespace App\Contracts;

use App\Models\SnagItem;
use App\Models\Worker;

interface SnagNotifier
{
    public function assigned(SnagItem $snag, Worker $worker, string $publicUrl): void;

    public function rework(SnagItem $snag, Worker $worker, string $publicUrl, ?string $note = null): void;
}
