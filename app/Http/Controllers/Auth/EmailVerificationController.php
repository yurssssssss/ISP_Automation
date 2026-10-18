<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\EmailCode;
use Illuminate\Http\Request;

/** "Enter the 6-digit code we emailed you" — required once per account. */
class EmailVerificationController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();
        if ($user->hasVerifiedEmail()) {
            return redirect()->route('home');
        }

        // First visit: send a code without making them click.
        if (! EmailCode::hasActive($user->email, EmailCode::VERIFY) && ! session('code_sent')) {
            $result = EmailCode::send($user, $user->email, EmailCode::VERIFY);
            session()->now($result === 'sent' ? 'status' : 'error', $result === 'sent'
                ? 'We sent a 6-digit code to your email.'
                : EmailCode::sendMessage($result, $user->email, EmailCode::VERIFY));
        }

        return view('auth.verify-email', [
            'email' => $user->email,
            'resendIn' => EmailCode::secondsUntilResend($user->email, EmailCode::VERIFY),
        ]);
    }

    public function resend(Request $request)
    {
        $user = $request->user();
        $result = EmailCode::send($user, $user->email, EmailCode::VERIFY);

        return back()->with('code_sent', true)->with($result === 'sent' ? 'status' : 'error', $result === 'sent'
            ? 'A new code is on its way.'
            : EmailCode::sendMessage($result, $user->email, EmailCode::VERIFY));
    }

    public function verify(Request $request)
    {
        $request->validate(['code' => ['required', 'string', 'regex:/^\s*\d{3}\s?\d{3}\s*$/']]);
        $user = $request->user();

        $result = EmailCode::check($user->email, EmailCode::VERIFY, $request->input('code'));
        if ($result !== 'ok') {
            return back()->with('code_sent', true)->withErrors(['code' => EmailCode::checkMessage($result)]);
        }

        $user->markEmailAsVerified();

        return redirect()->route('home')->with('status', 'Email verified. Welcome!');
    }
}
