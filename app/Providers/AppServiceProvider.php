<?php

namespace App\Providers;

use App\Services\MikroTik\MikroTikGateway;
use App\Services\MikroTik\RouterGateway;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\Mailer\Bridge\Brevo\Transport\BrevoTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // A fresh connection per use (each queued job logs in once).
        $this->app->bind(RouterGateway::class, fn () => MikroTikGateway::fromConfig());
    }

    public function boot(): void
    {
        // MAIL_MAILER=brevo: sends through Brevo's HTTP API.
        Mail::extend('brevo', fn () => (new BrevoTransportFactory)->create(
            new Dsn('brevo+api', 'default', config('services.brevo.key'))
        ));

        // A public demo link must never send real emails, take payments or
        // touch a router (sample customers would become real PPPoE accounts).
        if (config('isp.demo')) {
            config([
                'mail.default' => 'log',
                'services.paymongo.secret_key' => null,
                'isp.mikrotik.host' => null,
                'isp.mikrotik.password' => '',
            ]);
        }

        // `composer run dev` also starts the scheduler (billing automation);
        // the queue worker is one of the defaults.
        if ($this->app->runningInConsole()) {
            DevCommands::artisan('schedule:work', 'scheduler');
        }

        // Choosing a password (reset / first password): 8+ characters with an upper- and a
        // lowercase letter, a number and a symbol.
        Password::defaults(fn () => Password::min(8)->mixedCase()->numbers()->symbols());

        // POST /login — per IP across all accounts (stops one machine from
        // spraying many emails). Wrong-password lockout per account lives in
        // LoginController.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(20)->by('login-ip:'.$request->ip()));

        // Live email check while typing (several per form is normal).
        RateLimiter::for('email-check', fn (Request $request) => Limit::perMinute(30)->by('email-check:'.($request->user()?->id ?: $request->ip())));

        // Sending codes and checking codes. EmailCode has its own per-email
        // limits; these cap a single IP.
        RateLimiter::for('otp-send', fn (Request $request) => [
            Limit::perMinute(3)->by('otp-send:'.$request->ip()),
            Limit::perHour(20)->by('otp-send-h:'.$request->ip()),
        ]);
        RateLimiter::for('otp-check', fn (Request $request) => Limit::perMinute(10)->by('otp-check:'.Str::lower((string) $request->input('email', $request->user()?->email)).'|'.$request->ip()));

        // Payment link creation.
        RateLimiter::for('pay', fn (Request $request) => Limit::perMinute(6)->by('pay:'.($request->user()?->id ?: $request->ip())));
    }
}
