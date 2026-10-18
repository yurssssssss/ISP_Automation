<?php

namespace App\Mail;

use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentReceived extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Payment $payment) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Payment received — '.$this->payment->invoice->number);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.payment-received', with: [
            'invoice' => $this->payment->invoice,
            'subscription' => $this->payment->invoice->subscription,
        ]);
    }
}
