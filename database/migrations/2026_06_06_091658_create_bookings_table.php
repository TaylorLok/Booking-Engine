<?php

use App\Enums\BookingStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();
            $table->uuid('idempotency_key')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('status', BookingStatus::values())->default(BookingStatus::Pending->value);
            $table->date('check_in');
            $table->date('check_out');
            $table->unsignedTinyInteger('adults')->default(1);
            $table->unsignedTinyInteger('children')->default(0);
            $table->unsignedInteger('subtotal_cents');
            $table->unsignedInteger('taxes_cents')->default(0);
            $table->unsignedInteger('total_cents');
            $table->text('special_requests')->nullable();
            $table->string('external_reference', 100)->nullable();
            $table->json('external_response_snapshot')->nullable();
            $table->text('failure_reason')->nullable();
            $table->unsignedTinyInteger('api_attempt_count')->default(0);
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            
            $table->index(['user_id', 'status']);
            $table->index(['status', 'created_at']);
            $table->index('check_in');
            $table->index('reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
