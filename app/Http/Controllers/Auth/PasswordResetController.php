<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\EmailCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Forgot password → a 6-digit code by email → new password. Also how a new
 * client sets their first password (the admin never needs to know it).
 * The screens never reveal whether an email has an account.
 */
class PasswordResetController extends Controller
{
    public function request()
    {
        return view('auth.forgot-password');
    }

    public function send(Request $request)
    {
        $email = Str::lower(trim($request->validate(['email' => ['required', 'email', 'max:255']])['email']));

        $user = User::where('email', $email)->where('is_active', true)->first();
        if ($user) {
            EmailCode::send($user, $email, EmailCode::RESET);
        }

        return redirect()->route('password.reset', ['email' => $email])
            ->with('status', 'If an account uses that email, we sent it a 6-digit code. It expires in '.EmailCode::MINUTES.' minutes.');
    }

    public function edit(Request $request)
    {
        return view('auth.reset-password', ['email' => (string) $request->query('email')]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'code' => ['required', 'string', 'regex:/^\s*\d{3}\s?\d{3}\s*$/'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);
        $email = Str::lower(trim($data['email']));

        $result = EmailCode::check($email, EmailCode::RESET, $data['code']);
        $user = User::where('email', $email)->first();

        if ($result !== 'ok' || ! $user) {
            return back()->withInput($request->only('email'))->withErrors(['code' => EmailCode::checkMessage($result)]);
        }

        if (Hash::check($data['password'], $user->password)) {
            return back()->withInput($request->only('email'))
                ->withErrors(['password' => 'Your new password must be different from your current password.']);
        }

        $user->forceFill([
            'password' => $data['password'],
            'remember_token' => Str::random(60),
        ])->save();

        // The code proved they own the inbox.
        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        // Sign out every other device that had the old password.
        DB::table('sessions')->where('user_id', $user->id)->delete();

        // The lockout message tells a locked-out person to reset; honour it.
        LoginController::clearLockout($email, $request->ip());

        return redirect()->route('login')->with('status', 'Password changed. You can sign in now.');
    }
}
