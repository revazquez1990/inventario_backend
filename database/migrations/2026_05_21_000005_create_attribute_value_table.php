<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attribute_value', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attribute_id')->constrained('attribute');
            $table->string('value', 60);
            $table->enum('status', ['active', 'inactive', 'deleted'])->default('active')->index();
            $table->timestamps();

            $table->unique(['attribute_id', 'value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attribute_value');
    }
};
