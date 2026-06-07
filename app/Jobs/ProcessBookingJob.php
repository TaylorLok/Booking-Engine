<?php

namespace App\Jobs;

use App\Models\Booking;
use App\Services\BookingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessBookingJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public int $bookingId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(BookingService $bookingService): void
    {
        if ($bookingService->isCircuitOpen()) {
            $this->release(60);

            return;
        }

        $booking = Booking::query()->find($this->bookingId);

        if ($booking === null) {
            return;
        }

        $bookingService->process($booking);
    }

    public function failed(?Throwable $exception): void
    {
        $booking = Booking::query()->find($this->bookingId);

        if ($booking === null) {
            return;
        }

        app(BookingService::class)->failBooking(
            $booking,
            'External API unavailable after '.$this->tries.' attempts',
            'process_booking_job',
        );
    }
}
