<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Login with two lockouts on top of the per-IP route throttle:
 *  - 5 wrong passwords for one email from one IP → 15 minutes;
 *  - 10 wrong passwords for one email from anywhere → 15 minutes
 *    (stops a password-guessing botnet rotating IPs).
 * The error never says whether the email exists.
 */
class LoginController extends Controller
{
    private const MAX_PER_IP = 5;

    private const MAX_PER_ACCOUNT = 10;

    private const LOCK_SECONDS = 900;

    public function show()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $email = Str::lower(trim($credentials['email']));
        $keys = [
            self::ipKey($email, $request->ip()) => self::MAX_PER_IP,
            self::accountKey($email) => self::MAX_PER_ACCOUNT,
        ];

        foreach ($keys as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);
                throw ValidationException::withMessages([
                    'email' => "Too many failed sign-in attempts. Please try again in {$minutes} minute".($minutes === 1 ? '' : 's').' or reset your password.',
                ]);
            }
        }

        if (! Auth::attempt(['email' => $email, 'password' => $credentials['password']], $request->boolean('remember'))) {
            foreach (array_keys($keys) as $key) {
                RateLimiter::hit($key, self::LOCK_SECONDS);
            }
            Log::warning('Failed login', ['email' => $email, 'ip' => $request->ip()]);

            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }

        /** @var User $user */
        $user = Auth::user();

        if (! $user->is_active) {
            Auth::logout();
            throw ValidationException::withMessages(['email' => 'This account has been disabled. Please contact us.']);
        }

        foreach (array_keys($keys) as $key) {
            RateLimiter::clear($key);
        }

        // New session id on login (session fixation).
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        $dashboard = route($user->dashboardRoute());

        return redirect()->to($this->intendedUrlWithinArea($request, $dashboard))
            ->with('status', $user->isAdmin() ? 'Signed in as administrator.' : "Welcome back, {$user->name}.");
    }

    /**
     * The page they were trying to open before signing in — but only if it
     * belongs to their own role's area. A client who had an admin link open
     * (or the reverse) goes to their own dashboard instead of a 403.
     */
    private function intendedUrlWithinArea(Request $request, string $dashboard): string
    {
        $intended = (string) $request->session()->pull('url.intended', '');

        return $intended !== '' && ($intended === $dashboard || str_starts_with($intended, $dashboard.'/'))
            ? $intended
            : $dashboard;
    }

    /**
     * Lifts the wrong-password lockout — after a successful password reset,
     * which the lockout message suggests ("…or reset your password").
     */
    public static function clearLockout(string $email, ?string $ip): void
    {
        $email = Str::lower(trim($email));
        RateLimiter::clear(self::accountKey($email));
        if ($ip) {
            RateLimiter::clear(self::ipKey($email, $ip));
        }
    }

    private static function accountKey(string $email): string
    {
        return 'login-fail:'.$email;
    }

    private static function ipKey(string $email, ?string $ip): string
    {
        return 'login-fail:'.$email.'|'.$ip;
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
