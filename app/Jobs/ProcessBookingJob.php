<?php

namespace App\Jobs;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Services\BookingService;
use App\Support\BookingFlowLogger;
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

    public function handle(BookingService $bookingService, BookingFlowLogger $logger): void
    {
        $logger->info('job.process_booking.started', [
            'booking_id' => $this->bookingId,
            'attempt' => $this->attempts(),
            'max_tries' => $this->tries,
            'queue' => $this->queue,
        ]);

        if ($bookingService->isCircuitOpen()) {
            $logger->warning('job.process_booking.circuit_breaker_open', [
                'booking_id' => $this->bookingId,
                'attempt' => $this->attempts(),
                'release_delay_seconds' => 60,
            ]);

            $this->release(60);

            return;
        }

        $booking = Booking::query()->find($this->bookingId);

        if ($booking === null) {
            $logger->warning('job.process_booking.booking_not_found', [
                'booking_id' => $this->bookingId,
                'attempt' => $this->attempts(),
            ]);

            return;
        }

        if ($booking->status !== BookingStatus::Pending) {
            $logger->info('job.process_booking.skipped_non_pending', [
                ...$logger->booking($booking),
                'attempt' => $this->attempts(),
            ]);

            return;
        }

        try {
            $bookingService->process($booking);

            $booking->refresh();

            $logger->info('job.process_booking.completed', [
                ...$logger->booking($booking),
                'attempt' => $this->attempts(),
            ]);
        } catch (Throwable $exception) {
            $logger->error('job.process_booking.failed_attempt', [
                ...$logger->booking($booking),
                'attempt' => $this->attempts(),
                'will_retry' => $this->attempts() < $this->tries,
            ], $exception);

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $logger = app(BookingFlowLogger::class);

        $booking = Booking::query()->find($this->bookingId);

        if ($booking === null) {
            $logger->error('job.process_booking.final_failure_booking_not_found', [
                'booking_id' => $this->bookingId,
                'max_tries' => $this->tries,
            ], $exception);

            return;
        }

        $logger->error('job.process_booking.final_failure', [
            ...$logger->booking($booking),
            'max_tries' => $this->tries,
        ], $exception);

        app(BookingService::class)->failBooking(
            $booking,
            'External API unavailable after '.$this->tries.' attempts',
            'process_booking_job',
        );
    }
}
