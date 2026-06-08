<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('product_attribute_value', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('product')->cascadeOnDelete();
            $table->foreignId('attribute_value_id')->constrained('attribute_value')->cascadeOnDelete();
            $table->unique(['product_id', 'attribute_value_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_attribute_value');
    }
};
