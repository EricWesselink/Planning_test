<?php

use App\Enums\FlooringSpecialty;
use App\Models\SpecialtyOption;
use App\Models\Worker;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('specialty_options', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64)->unique();
            $table->timestamps();
        });

        foreach (Worker::query()->whereNotNull('specialty')->pluck('specialty') as $stored) {
            foreach (FlooringSpecialty::parts((string) $stored) as $part) {
                SpecialtyOption::remember($part);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('specialty_options');
    }
};
