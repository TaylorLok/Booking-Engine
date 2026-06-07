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
        Schema::create('booking_rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('room_id')->constrained('rooms')->restrictOnDelete();
            $table->unsignedTinyInteger('adults')->default(1);
            $table->unsignedTinyInteger('children')->default(0);
            $table->unsignedInteger('price_per_night_cents');
            $table->unsignedSmallInteger('nights_count');
            $table->unsignedInteger('line_total_cents')->storedAs('price_per_night_cents * nights_count');
            $table->timestamps();

            $table->unique(['booking_id', 'room_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('booking_rooms');
    }
};
