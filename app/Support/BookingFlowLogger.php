<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomHold;
use Illuminate\Support\Facades\Log;
use Psr\Log\LogLevel;
use Throwable;

class BookingFlowLogger
{
    private const CHANNEL = 'booking';

    public function info(string $event, array $context = []): void
    {
        $this->write(LogLevel::INFO, $event, $context);
    }

    public function debug(string $event, array $context = []): void
    {
        $this->write(LogLevel::DEBUG, $event, $context);
    }

    public function warning(string $event, array $context = []): void
    {
        $this->write(LogLevel::WARNING, $event, $context);
    }

    public function error(string $event, array $context = [], ?Throwable $exception = null): void
    {
        if ($exception !== null) {
            $context['exception'] = $exception->getMessage();
            $context['exception_class'] = $exception::class;
            $context['trace'] = $exception->getTraceAsString();
        }

        $this->write(LogLevel::ERROR, $event, $context);
    }

    /**
     * @return array<string, mixed>
     */
    public function booking(Booking $booking): array
    {
        return [
            'booking_id' => $booking->id,
            'reference' => $booking->reference,
            'status' => $booking->status->value,
            'user_id' => $booking->user_id,
            'idempotency_key' => $booking->idempotency_key,
            'check_in' => $booking->check_in?->toDateString(),
            'check_out' => $booking->check_out?->toDateString(),
            'adults' => $booking->adults,
            'children' => $booking->children,
            'subtotal_cents' => $booking->subtotal_cents,
            'taxes_cents' => $booking->taxes_cents,
            'total_cents' => $booking->total_cents,
            'api_attempt_count' => $booking->api_attempt_count,
            'external_reference' => $booking->external_reference,
            'failure_reason' => $booking->failure_reason,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function room(Room $room): array
    {
        return [
            'room_id' => $room->id,
            'room_slug' => $room->slug,
            'room_name' => $room->name,
            'total_units' => $room->total_units,
            'max_adults' => $room->max_adults,
            'max_children' => $room->max_children,
            'price_per_night_cents' => $room->price_per_night_cents,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function hold(RoomHold $hold): array
    {
        return [
            'hold_id' => $hold->id,
            'room_id' => $hold->room_id,
            'booking_id' => $hold->booking_id,
            'check_in' => $hold->check_in?->toDateString(),
            'check_out' => $hold->check_out?->toDateString(),
            'expires_at' => $hold->expires_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function write(string $level, string $event, array $context): void
    {
        Log::channel(self::CHANNEL)->log($level, $event, [
            'event' => $event,
            ...$context,
        ]);
    }
}
