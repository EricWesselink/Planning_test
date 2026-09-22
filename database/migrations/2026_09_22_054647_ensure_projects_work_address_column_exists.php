<?php

use App\Support\WorkAddress;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        WorkAddress::ensureColumn();
    }

    public function down(): void
    {
        // The earlier work_address migration owns this column. Rolling this back must not delete addresses.
    }
};
