<?php

use App\Support\ShopWorkCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('kind', 32)->default('project')->after('status');
            $table->text('work_description')->nullable()->after('notes');
            $table->index('kind');
        });

        Schema::create('work_activity_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('work_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_activity_category_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['work_activity_category_id', 'sort_order']);
        });

        Schema::create('project_work_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_activity_id')->constrained()->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['project_id', 'work_activity_id']);
        });

        Schema::table('work_items', function (Blueprint $table) {
            $table->foreignId('work_activity_id')->nullable()->after('project_id')->constrained()->nullOnDelete();
        });

        ShopWorkCatalog::seed();
    }

    public function down(): void
    {
        Schema::table('work_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('work_activity_id');
        });

        Schema::dropIfExists('project_work_activities');
        Schema::dropIfExists('work_activities');
        Schema::dropIfExists('work_activity_categories');

        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndex(['kind']);
            $table->dropColumn(['kind', 'work_description']);
        });
    }
};
