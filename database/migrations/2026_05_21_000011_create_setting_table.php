<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('setting', function (Blueprint $table) {
            $table->string('key', 60)->primary();
            $table->text('value');
            $table->foreignId('updated_by_user_id')->nullable()->constrained('user');
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('setting');
    }
};
