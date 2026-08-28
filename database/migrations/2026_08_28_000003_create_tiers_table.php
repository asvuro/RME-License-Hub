<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tiers', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 50)->unique()->comment('starter, standard, pro, enterprise');
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('base_max_users')->default(0)->comment('0 = unlimited (untuk enterprise)');
            $table->unsignedInteger('default_duration_days')->default(365);
            $table->json('included_modules')->comment('Array nama modul yang disertakan dalam tier ini');
            $table->json('metadata')->nullable()->comment('Info tambahan: base_price, features, dll');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tiers');
    }
};
