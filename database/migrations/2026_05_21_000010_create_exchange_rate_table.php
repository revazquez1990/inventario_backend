<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rate', function (Blueprint $table) {
            $table->id();
            $table->date('rate_date')->unique();
            $table->decimal('usd_to_cup', 12, 4);
            $table->foreignId('created_by_user_id')->constrained('user');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rate');
    }
};
