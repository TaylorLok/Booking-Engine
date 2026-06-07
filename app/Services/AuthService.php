<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthService
{
    /**
     * @param  array{firstname: string, surname: string, cellphone: string, email: string, password: string}  $data
     */
    public function register(array $data): User
    {
        $user = User::query()->create($data);

        Auth::login($user);

        return $user;
    }

    /**
     * @param  array{email: string, password: string}  $credentials
     *
     * @throws ValidationException
     */
    public function login(array $credentials): User
    {
        if (! Auth::attempt($credentials)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    public function logout(): void
    {
        Auth::guard('web')->logout();
    }

    /**
     * @return array<string, mixed>
     */
    public function formatUser(User $user): array
    {
        return [
            'id' => $user->id,
            'firstname' => $user->firstname,
            'surname' => $user->surname,
            'email' => $user->email,
            'cellphone' => $user->cellphone,
        ];
    }
}
