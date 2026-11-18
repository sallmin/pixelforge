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
        Schema::create('generation_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('generation_id')->unique()->constrained('generations')->cascadeOnDelete();
            $table->unsignedBigInteger('provider_cost');
            $table->unsignedBigInteger('charged');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('generation_charges');
    }
};
