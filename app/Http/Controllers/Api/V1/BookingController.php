<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\StoreBookingRequest;
use App\Services\BookingService;
use App\Support\BookingFlowLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    public function store(
        StoreBookingRequest $request,
        BookingService $bookingService,
        BookingFlowLogger $logger,
    ): JsonResponse {
        $logger->info('api.booking.store.received', [
            'user_id' => $request->user()->id,
            'idempotency_key' => $request->input('idempotency_key'),
            'check_in' => $request->input('check_in'),
            'check_out' => $request->input('check_out'),
            'room_ids' => collect($request->input('rooms', []))->pluck('room_id')->all(),
        ]);

        $booking = $bookingService->create($request->user(), $request->validated());

        $logger->info('api.booking.store.responded', [
            ...$logger->booking($booking),
            'http_status' => 201,
        ]);

        return response()->json($bookingService->formatBooking($booking), 201);
    }

    public function show(
        string $reference,
        Request $request,
        BookingService $bookingService,
        BookingFlowLogger $logger,
    ): JsonResponse {
        $booking = $bookingService->findByReference($reference, $request->user());

        $logger->info('api.booking.show', [
            ...$logger->booking($booking),
            'user_id' => $request->user()->id,
        ]);

        return response()->json($bookingService->formatBooking($booking));
    }
}
