<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingStatusController extends Controller
{
    public function show(string $reference, Request $request, BookingService $bookingService): JsonResponse
    {
        $booking = $bookingService->findByReference($reference, $request->user());

        return response()->json($bookingService->formatStatus($booking));
    }
}
