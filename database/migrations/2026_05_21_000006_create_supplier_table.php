<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160)->index();
            $table->string('contact_name', 120)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email', 180)->nullable();
            $table->text('address')->nullable();
            $table->text('notes')->nullable();
            $table->enum('status', ['active', 'inactive', 'deleted'])->default('active')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier');
    }
};
