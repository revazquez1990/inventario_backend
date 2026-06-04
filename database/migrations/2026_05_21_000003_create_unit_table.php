<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unit', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->string('abbreviation', 10)->unique();
            $table->enum('status', ['active', 'inactive', 'deleted'])->default('active')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unit');
    }
};
