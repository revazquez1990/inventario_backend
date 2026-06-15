<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_warehouse', function (Blueprint $table) {
            // Per-store selling price for a product (null for almacenes).
            $table->decimal('sale_price', 12, 2)->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('product_warehouse', function (Blueprint $table) {
            $table->dropColumn('sale_price');
        });
    }
};
