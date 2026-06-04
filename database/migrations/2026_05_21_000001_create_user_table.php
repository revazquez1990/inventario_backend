<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('email', 180)->unique();
            $table->string('password');
            $table->enum('role', ['admin', 'almacenero']);
            $table->enum('status', ['active', 'inactive', 'deleted'])->default('active')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user');
    }
};
