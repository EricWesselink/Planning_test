<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calculation_lines', function (Blueprint $table) {
            $table->decimal('original_quantity', 12, 3)->nullable()->after('quantity');
            $table->string('original_product_code')->nullable()->after('product_code');
            $table->string('original_product')->nullable()->after('product');
            $table->string('found_source', 32)->nullable()->after('source');
            $table->boolean('confirmed_manually')->default(false)->after('note');
            $table->timestamp('confirmed_at')->nullable()->after('confirmed_manually');
            $table->text('calculation_trace')->nullable()->after('confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('calculation_lines', function (Blueprint $table) {
            $table->dropColumn([
                'original_quantity',
                'original_product_code',
                'original_product',
                'found_source',
                'confirmed_manually',
                'confirmed_at',
                'calculation_trace',
            ]);
        });
    }
};
