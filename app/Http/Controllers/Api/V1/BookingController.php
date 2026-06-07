<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\StoreBookingRequest;
use App\Services\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    public function store(StoreBookingRequest $request, BookingService $bookingService): JsonResponse
    {
        $booking = $bookingService->create($request->user(), $request->validated());

        return response()->json($bookingService->formatBooking($booking), 201);
    }

    public function show(string $reference, Request $request, BookingService $bookingService): JsonResponse
    {
        $booking = $bookingService->findByReference($reference, $request->user());

        return response()->json($bookingService->formatBooking($booking));
    }
}
