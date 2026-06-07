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
        Schema::create('room_holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('rooms')->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->date('check_in');
            $table->date('check_out');
            $table->timestamp('expires_at');
            $table->timestamps();
            
            $table->unique(['room_id', 'booking_id'], 'uq_room_hold');
            $table->index(['room_id', 'check_in', 'check_out', 'expires_at'], 'idx_hold_availability');
            $table->index('expires_at', 'idx_hold_expiry');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('room_holds');
    }
};
