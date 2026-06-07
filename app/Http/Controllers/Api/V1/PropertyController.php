<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\PropertyService;
use Illuminate\Http\JsonResponse;

class PropertyController extends Controller
{
    public function show(PropertyService $propertyService): JsonResponse
    {
        return response()
            ->json($propertyService->getData())
            ->header('Cache-Control', 'public, max-age=3600, stale-while-revalidate=86400');
    }
}
