<?php

use App\Models\Worker;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Worker::query()->orderBy('id')->lazyById()->each(
            fn (Worker $worker) => $worker->collapseDuplicateCrewPeople(),
        );
    }

    public function down(): void
    {
        // Duplicate teammate names cannot be restored.
    }
};
