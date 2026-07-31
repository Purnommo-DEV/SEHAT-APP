<?php

namespace App\Services\Auth;

use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthenticationService
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    public function authenticate(
        string $email,
        string $password,
        bool $remember,
        string $ipAddress,
        Session $session,
    ): void {
        $throttleKey = $this->throttleKey($email, $ipAddress);

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages([
                'email' => "Terlalu banyak percobaan masuk. Coba kembali dalam {$seconds} detik.",
            ]);
        }

        $authenticated = Auth::guard('web')->attempt([
            'email' => $email,
            'password' => $password,
            'is_active' => true,
        ], $remember);

        if (! $authenticated) {
            RateLimiter::hit($throttleKey, self::DECAY_SECONDS);

            throw ValidationException::withMessages([
                'email' => 'Email atau kata sandi tidak sesuai, atau akun sedang dinonaktifkan.',
            ]);
        }

        RateLimiter::clear($throttleKey);
        $session->regenerate();
    }

    public function logout(Session $session): void
    {
        Auth::guard('web')->logout();
        $session->invalidate();
        $session->regenerateToken();
    }

    private function throttleKey(string $email, string $ipAddress): string
    {
        return Str::transliterate(Str::lower($email).'|'.$ipAddress);
    }
}
