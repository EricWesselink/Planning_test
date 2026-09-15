<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_documents', function (Blueprint $table) {
            $table->unsignedInteger('revision')->default(1)->after('document_type');
            $table->boolean('is_current')->default(true)->after('revision');
            $table->index(['project_id', 'document_type', 'is_current']);
        });

        $documents = DB::table('project_documents')->orderBy('id')->get();
        $groups = $documents->groupBy(fn (object $row): string => $row->project_id.'|'.$row->document_type);
        foreach ($groups as $rows) {
            $total = $rows->count();
            $revision = 0;
            foreach ($rows as $row) {
                $revision++;
                DB::table('project_documents')->where('id', $row->id)->update([
                    'revision' => $revision,
                    'is_current' => $revision === $total,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('project_documents', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'document_type', 'is_current']);
            $table->dropColumn(['revision', 'is_current']);
        });
    }
};
