<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function register(RegisterRequest $request, AuthService $authService): JsonResponse
    {
        $user = $authService->register($request->validated());

        return response()->json([
            'user' => $authService->formatUser($user),
        ], 201);
    }

    public function login(LoginRequest $request, AuthService $authService): JsonResponse
    {
        $user = $authService->login($request->validated());

        return response()->json([
            'user' => $authService->formatUser($user),
        ]);
    }

    public function logout(Request $request, AuthService $authService): JsonResponse
    {
        $authService->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    public function me(Request $request, AuthService $authService): JsonResponse
    {
        return response()->json([
            'user' => $authService->formatUser($request->user()),
        ]);
    }
}
