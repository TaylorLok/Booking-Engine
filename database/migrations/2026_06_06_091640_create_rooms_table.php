<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 100)->unique();
            $table->string('name', 200);
            $table->foreignId('room_type_id')->constrained('room_types')->restrictOnDelete();
            $table->unsignedTinyInteger('max_adults')->default(2);
            $table->unsignedTinyInteger('max_children')->default(0);
            $table->unsignedTinyInteger('max_occupancy')->storedAs('max_adults + max_children');
            $table->unsignedInteger('price_per_night_cents');
            $table->boolean('is_active')->default(true);
            $table->unsignedTinyInteger('total_units')->default(1);
            $table->timestamps();

            $table->index(['is_active', 'room_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
