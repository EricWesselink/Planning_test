<?php

use App\Models\Worker;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Worker::reassignCollidingColors();
    }
};
