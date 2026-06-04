<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movement_item', function (Blueprint $table) {
            $table->id();
            $table->foreignId('movement_id')->constrained('movement')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('product');
            $table->integer('quantity');
            $table->decimal('unit_price_with_tax_usd', 12, 2);
            $table->decimal('unit_price_with_tax_cup', 14, 2);
            $table->decimal('subtotal_with_tax_usd', 14, 2);
            $table->decimal('subtotal_tax_usd', 14, 2);
            $table->decimal('subtotal_without_tax_usd', 14, 2);
            $table->decimal('subtotal_with_tax_cup', 16, 2);
            $table->decimal('subtotal_tax_cup', 16, 2);
            $table->decimal('subtotal_without_tax_cup', 16, 2);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movement_item');
    }
};
