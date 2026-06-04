<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product', function (Blueprint $table) {
            $table->id();
            $table->string('code', 60)->unique();
            $table->string('name', 160);
            $table->foreignId('category_id')->constrained('category');
            $table->foreignId('unit_id')->constrained('unit');
            $table->decimal('price', 12, 2)->default(0);
            $table->string('reference', 160)->nullable();
            $table->unsignedInteger('quantity')->default(0);
            $table->string('image')->nullable();
            $table->enum('status', ['active', 'inactive', 'deleted'])->default('active')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product');
    }
};
