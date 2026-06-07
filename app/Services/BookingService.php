<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Jobs\ProcessBookingJob;
use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\BookingStatusEvent;
use App\Models\Room;
use App\Models\RoomHold;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class BookingService
{
    public function __construct(
        private readonly AvailabilityService $availabilityService,
    ) {}

    /**
     * Create a new pending booking and dispatch the processing job.
     *
     * @param  array{
     *     idempotency_key: string,
     *     check_in: string,
     *     check_out: string,
     *     adults: int,
     *     children?: int,
     *     rooms: list<array{room_id: int, adults: int, children?: int}>,
     *     special_requests?: string|null
     * }  $data
     */
    public function create(User $user, array $data): Booking
    {
        // --- Idempotency check ---
        // If this key was seen before, return the existing booking — no duplicate created.
        $existing = Booking::query()
            ->where('idempotency_key', $data['idempotency_key'])
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $checkIn  = Carbon::parse($data['check_in'])->startOfDay();
        $checkOut = Carbon::parse($data['check_out'])->startOfDay();
        $nights   = $checkIn->diffInDays($checkOut);

        // --- Availability pre-check ---
        // Not inside the transaction — a quick guard before we do any writes.
        // The real concurrency lock happens in placeHold() via SELECT FOR UPDATE.
        $availability = $this->availabilityService->checkRooms(
            $data['rooms'],
            $checkIn,
            $checkOut,
        );

        if (! $availability['available']) {
            throw ValidationException::withMessages([
                'rooms' => ['One or more selected rooms are no longer available for the chosen dates.'],
            ]);
        }

        // --- Pricing — always calculated server-side, never trusted from client ---
        $pricing = $this->calculatePricing($data['rooms'], $nights);

        $booking = DB::transaction(function () use ($user, $data, $checkIn, $checkOut, $nights, $pricing): Booking {
            $booking = Booking::query()->create([
                'reference'       => $this->generateReference(),
                'idempotency_key' => $data['idempotency_key'],
                'user_id'         => $user->id,
                'status'          => BookingStatus::Pending,
                'check_in'        => $checkIn,
                'check_out'       => $checkOut,
                'adults'          => $data['adults'],
                'children'        => $data['children'] ?? 0,
                'subtotal_cents'  => $pricing['subtotal_cents'],
                'taxes_cents'     => $pricing['taxes_cents'],
                'total_cents'     => $pricing['total_cents'],
                'special_requests' => $data['special_requests'] ?? null,
            ]);

            foreach ($pricing['lines'] as $line) {
                BookingRoom::query()->create([
                    'booking_id'             => $booking->id,
                    'room_id'                => $line['room_id'],
                    'adults'                 => $line['adults'],
                    'children'               => $line['children'],
                    'price_per_night_cents'  => $line['price_per_night_cents'],
                    'nights_count'           => $nights,
                ]);
            }

            $this->recordStatusChange(
                booking: $booking,
                from: null,
                to: BookingStatus::Pending,
                triggeredBy: 'api',
                metadata: ['action' => 'created'],
            );

            return $booking;
        });

        ProcessBookingJob::dispatch($booking->id)->onQueue('bookings');

        return $booking->load('bookingRooms.room');
    }

    /**
     * Find a booking by its human-readable reference.
     * Scoped to the authenticated user if provided.
     */
    public function findByReference(string $reference, ?User $user = null): Booking
    {
        $query = Booking::query()
            ->with(['bookingRooms.room', 'statusEvents'])
            ->where('reference', $reference);

        if ($user !== null) {
            $query->where('user_id', $user->id);
        }

        return $query->firstOrFail();
    }

    /**
     * Full booking detail — returned after submission.
     *
     * @return array<string, mixed>
     */
    public function formatBooking(Booking $booking): array
    {
        return [
            'reference'       => $booking->reference,
            'status'          => $booking->status->value,
            // idempotency_key intentionally omitted — internal use only
            'check_in'        => $booking->check_in->toDateString(),
            'check_out'       => $booking->check_out->toDateString(),
            'adults'          => $booking->adults,
            'children'        => $booking->children,
            'subtotal_cents'  => $booking->subtotal_cents,
            'taxes_cents'     => $booking->taxes_cents,
            'total_cents'     => $booking->total_cents,
            'special_requests' => $booking->special_requests,
            'failure_reason'  => $booking->failure_reason,
            'confirmed_at'    => $booking->confirmed_at?->toIso8601String(),
            'cancelled_at'    => $booking->cancelled_at?->toIso8601String(),
            'rooms'           => $booking->bookingRooms
                ->map(fn (BookingRoom $line): array => [
                    'room_id'               => $line->room_id,
                    'slug'                  => $line->room->slug,
                    'name'                  => $line->room->name,
                    'adults'                => $line->adults,
                    'children'              => $line->children,
                    'price_per_night_cents' => $line->price_per_night_cents,
                    'nights_count'          => $line->nights_count,
                    'line_total_cents'      => $line->line_total_cents,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Minimal status payload — used by the frontend polling endpoint.
     *
     * @return array<string, mixed>
     */
    public function formatStatus(Booking $booking): array
    {
        return [
            'reference'      => $booking->reference,
            'status'         => $booking->status->value,
            'failure_reason' => $booking->failure_reason,
            'confirmed_at'   => $booking->confirmed_at?->toIso8601String(),
            'cancelled_at'   => $booking->cancelled_at?->toIso8601String(),
        ];
    }

    /**
     * Called by ProcessBookingJob.
     * Places holds, calls the external API, and transitions the booking status.
     */
    public function process(Booking $booking): void
    {
        if ($booking->status !== BookingStatus::Pending) {
            return;
        }

        // --- Circuit breaker check ---
        // If the external API has been failing repeatedly, stop hammering it.
        if ($this->isCircuitOpen()) {
            throw new RuntimeException('Circuit breaker open — external API unavailable. Job will retry.');
        }

        $booking->loadMissing('bookingRooms.room');

        // --- Place holds with SELECT FOR UPDATE ---
        // This is the real concurrency lock. Two jobs cannot both pass this block
        // for the same room at the same time.
        try {
            DB::transaction(function () use ($booking): void {
                foreach ($booking->bookingRooms as $line) {
                    $this->placeHold($booking, $line->room);
                }
            });
        } catch (Throwable $exception) {
            $this->failBooking($booking, 'Room no longer available.', 'process_booking_job');
            throw $exception;
        }

        $booking->increment('api_attempt_count');

        // --- Call external API ---
        try {
            $externalReference = $this->submitToExternalApi($booking);

            DB::transaction(function () use ($booking, $externalReference): void {
                $fromStatus = $booking->status;

                $booking->update([
                    'status'             => BookingStatus::Confirmed,
                    'external_reference' => $externalReference,
                    'confirmed_at'       => now(),
                    'failure_reason'     => null,
                ]);

                // Release holds atomically with the status update —
                // both happen or neither happens.
                $this->releaseHolds($booking);

                $this->recordStatusChange(
                    booking: $booking,
                    from: $fromStatus,
                    to: BookingStatus::Confirmed,
                    triggeredBy: 'process_booking_job',
                );
            });
        } catch (Throwable $exception) {
            // Record the failure against the circuit breaker counter
            $this->recordExternalApiFailure();
            throw $exception;
        }
    }

    /**
     * Transition a booking to Failed, release its holds, and log the event.
     * Safe to call multiple times — bails out if already failed.
     */
    public function failBooking(Booking $booking, string $reason, string $triggeredBy = 'system'): void
    {
        if ($booking->status === BookingStatus::Failed) {
            return;
        }

        $fromStatus = $booking->status;

        $booking->update([
            'status'         => BookingStatus::Failed,
            'failure_reason' => $reason,
        ]);

        $this->releaseHolds($booking);

        $this->recordStatusChange(
            booking: $booking,
            from: $fromStatus,
            to: BookingStatus::Failed,
            triggeredBy: $triggeredBy,
            metadata: ['reason' => $reason],
        );
    }

    /**
     * Insert an immutable status event row.
     * Called whenever a booking transitions between statuses.
     */
    public function recordStatusChange(
        Booking $booking,
        ?BookingStatus $from,
        BookingStatus $to,
        string $triggeredBy = 'system',
        ?array $metadata = null,
    ): void {
        BookingStatusEvent::query()->create([
            'booking_id'  => $booking->id,
            'from_status' => $from,
            'to_status'   => $to,
            'triggered_by' => $triggeredBy,
            'metadata'    => $metadata,
        ]);
    }

    /**
     * Check if the circuit breaker is open.
     * Returns true when consecutive external API failures exceed the threshold.
     */
    public function isCircuitOpen(): bool
    {
        $threshold = config('booking.circuit_breaker.failure_threshold', 5);

        return (int) cache()->get('booking:circuit_breaker:failures', 0) >= $threshold;
    }

    /**
     * Increment the circuit breaker failure counter.
     * Counter expires after the configured window — auto-resets.
     */
    public function recordExternalApiFailure(): void
    {
        $window = config('booking.circuit_breaker.window_seconds', 60);
        $key    = 'booking:circuit_breaker:failures';

        if (! cache()->has($key)) {
            cache()->put($key, 1, $window);
            return;
        }

        cache()->increment($key);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Place a room hold inside a transaction with SELECT FOR UPDATE.
     * Throws if the room has no remaining units for the date range.
     */
    private function placeHold(Booking $booking, Room $room): void
    {
        // Lock existing holds for this room + date range so no concurrent
        // transaction can read a stale count.
        $existingHolds = RoomHold::query()
            ->where('room_id', $room->id)
            ->where('expires_at', '>', now())
            ->whereDateRangeOverlaps($booking->check_in, $booking->check_out)
            ->lockForUpdate()
            ->get();

        $confirmedCount = BookingRoom::query()
            ->where('room_id', $room->id)
            ->whereDateRangeOverlaps($booking->check_in, $booking->check_out)
            ->whereHas('booking', fn ($q) => $q->where('status', BookingStatus::Confirmed))
            ->count();

        $available = $room->total_units - $confirmedCount - $existingHolds->count();

        if ($available < 1) {
            throw new RuntimeException('Room no longer available.');
        }

        RoomHold::query()->updateOrCreate(
            [
                'room_id'    => $room->id,
                'booking_id' => $booking->id,
            ],
            [
                'check_in'   => $booking->check_in,
                'check_out'  => $booking->check_out,
                'expires_at' => now()->addMinutes(
                    config('booking.hold_duration_minutes', 15)
                ),
            ],
        );
    }

    /**
     * Delete all holds for a booking.
     * Called on confirmation, failure, or cancellation.
     */
    private function releaseHolds(Booking $booking): void
    {
        RoomHold::query()
            ->where('booking_id', $booking->id)
            ->delete();
    }

    /**
     * Submit the booking to the external property management API.
     * Returns the external reference on success, throws on failure.
     *
     * If no external API URL is configured (local/dev), returns a
     * synthetic LOCAL- reference so the flow completes end-to-end.
     */
    private function submitToExternalApi(Booking $booking): ?string
    {
        $url = config('booking.external_api.url');

        // No external API configured — local/dev shortcut
        if (empty($url)) {
            return 'LOCAL-' . $booking->reference;
        }

        $response = Http::timeout(config('booking.external_api.timeout', 10))
            ->withToken(config('booking.external_api.key', ''))
            ->post($url, [
                'reference'  => $booking->reference,
                'check_in'   => $booking->check_in->toDateString(),
                'check_out'  => $booking->check_out->toDateString(),
                'total_cents' => $booking->total_cents,
                'rooms'      => $booking->bookingRooms
                    ->map(fn (BookingRoom $line): array => [
                        'room_id'  => $line->room_id,
                        'adults'   => $line->adults,
                        'children' => $line->children,
                    ])
                    ->values()
                    ->all(),
            ]);

        // Always snapshot the raw response for audit — even on failure
        $payload = $response->json();

        $booking->update([
            'external_response_snapshot' => is_array($payload)
                ? $payload
                : ['body' => $response->body(), 'status' => $response->status()],
        ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                'External booking API returned ' . $response->status()
            );
        }

        return is_array($payload) ? ($payload['reference'] ?? null) : null;
    }

    /**
     * Calculate pricing from canonical DB room prices.
     * The client-submitted price is never used — always recalculated here.
     *
     * @param  list<array{room_id: int, adults: int, children?: int}>  $roomSelections
     * @return array{
     *     subtotal_cents: int,
     *     taxes_cents: int,
     *     total_cents: int,
     *     lines: list<array{room_id: int, adults: int, children: int, price_per_night_cents: int}>
     * }
     */
    private function calculatePricing(array $roomSelections, int $nights): array
    {
        $roomIds = collect($roomSelections)->pluck('room_id')->all();

        $rooms = Room::query()
            ->whereIn('id', $roomIds)
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        $lines    = [];
        $subtotal = 0;

        foreach ($roomSelections as $selection) {
            $room = $rooms->get($selection['room_id']);

            if ($room === null) {
                throw ValidationException::withMessages([
                    'rooms' => ['One or more selected rooms are unavailable.'],
                ]);
            }

            $subtotal += $room->price_per_night_cents * $nights;

            $lines[] = [
                'room_id'               => $room->id,
                'adults'                => (int) $selection['adults'],
                'children'              => (int) ($selection['children'] ?? 0),
                'price_per_night_cents' => $room->price_per_night_cents,
            ];
        }

        // Tax rate stored in basis points (bps): 1500 bps = 15%
        $taxRateBps = (int) config('booking.tax_rate_bps', 0);
        $taxes      = (int) round($subtotal * $taxRateBps / 10000);

        return [
            'subtotal_cents' => $subtotal,
            'taxes_cents'    => $taxes,
            'total_cents'    => $subtotal + $taxes,
            'lines'          => $lines,
        ];
    }

    /**
     * Generate a unique human-readable booking reference.
     * Format: BK-20240815-A3F9
     *
     * Uses a do-while loop with a unique constraint as the final guard.
     * Collision probability is negligible but the DB constraint catches it
     * if two requests somehow generate the same reference simultaneously.
     */
    private function generateReference(): string
    {
        $prefix = config('booking.reference_prefix', 'BK');

        do {
            $reference = sprintf(
                '%s-%s-%s',
                $prefix,
                now()->format('Ymd'),
                strtoupper(Str::random(4)),
            );
        } while (Booking::query()->where('reference', $reference)->exists());

        return $reference;
    }
}
