<?php

namespace App\Mail;

use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "Your account is ready": sent when an admin adds a customer (optional for
 * CSV imports). Points them to "Forgot password" to choose their portal
 * password — no password is ever put in the email.
 */
class ClientWelcome extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Subscription $subscription) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your '.config('app.name').' account is ready');
    }

    public function content(): Content
    {
        $client = $this->subscription->client;

        return new Content(markdown: 'mail.client-welcome', with: [
            'client' => $client,
            'plan' => $this->subscription->plan,
            'setPasswordUrl' => route('password.request', ['email' => $client->user?->email ?? $client->email]),
        ]);
    }
}
