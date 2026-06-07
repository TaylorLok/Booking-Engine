<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\BookingService;
use App\Support\BookingFlowLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingStatusController extends Controller
{
    public function show(
        string $reference,
        Request $request,
        BookingService $bookingService,
        BookingFlowLogger $logger,
    ): JsonResponse {
        $booking = $bookingService->findByReference($reference, $request->user());

        $logger->info('api.booking.status_polled', [
            ...$logger->booking($booking),
            'user_id' => $request->user()->id,
        ]);

        return response()->json($bookingService->formatStatus($booking));
    }
}
