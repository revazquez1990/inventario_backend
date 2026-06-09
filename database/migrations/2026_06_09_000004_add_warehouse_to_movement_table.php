<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movement', function (Blueprint $table) {
            $table->foreignId('warehouse_id')->after('status')->constrained('warehouse');
            $table->foreignId('to_warehouse_id')->nullable()->after('warehouse_id')->constrained('warehouse');
        });
    }

    public function down(): void
    {
        Schema::table('movement', function (Blueprint $table) {
            $table->dropForeign(['to_warehouse_id']);
            $table->dropForeign(['warehouse_id']);
            $table->dropColumn(['to_warehouse_id', 'warehouse_id']);
        });
    }
};
