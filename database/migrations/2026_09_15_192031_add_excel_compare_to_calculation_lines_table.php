<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calculation_lines', function (Blueprint $table) {
            $table->foreignId('calculation_workbook_id')->nullable()->after('calculation_drawing_id')->constrained()->nullOnDelete();
            $table->decimal('excel_quantity', 12, 3)->nullable()->after('original_quantity');
            $table->string('excel_product_code')->nullable()->after('original_product');
            $table->string('excel_product')->nullable()->after('excel_product_code');
        });
    }

    public function down(): void
    {
        Schema::table('calculation_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('calculation_workbook_id');
            $table->dropColumn(['excel_quantity', 'excel_product_code', 'excel_product']);
        });
    }
};
