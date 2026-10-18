<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\MailDomains;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Live email check behind inputs marked data-email-check (public/js/app.js).
 * Advice for the form only — the submit still validates everything.
 *
 *  - "existing" (login, forgot password): is it a complete address with a
 *    real provider. Never says whether an account uses it, so these screens
 *    still can't be used to find out who is a customer.
 *  - "new" (admin adding a customer): also whether the email is still free.
 *    Admins only.
 */
class EmailCheckController extends Controller
{
    public function __invoke(Request $request, MailDomains $mailDomains): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'max:255'],
            'context' => ['required', 'in:existing,new'],
        ]);

        $isActiveAdmin = $request->user()?->isAdmin() && $request->user()->is_active;
        abort_if($data['context'] === 'new' && ! $isActiveAdmin, 403);

        $email = Str::lower(trim($data['email']));
        $domain = Str::after($email, '@');

        if (validator(['email' => $email], ['email' => 'email:rfc'])->fails() || ! str_contains($domain, '.')) {
            return $this->reply(false, 'Enter a complete email address, like name@gmail.com.');
        }

        if (! $mailDomains->receivesMail($domain)) {
            return $this->reply(false, "We can't find the email provider \"{$domain}\". Please check for typos.");
        }

        if ($data['context'] === 'existing') {
            return $this->reply(true, '');
        }

        return User::where('email', $email)->exists()
            ? $this->reply(false, 'This email already has an account.')
            : $this->reply(true, 'This email is available.');
    }

    private function reply(bool $ok, string $message): JsonResponse
    {
        return response()->json(['ok' => $ok, 'message' => $message]);
    }
}
