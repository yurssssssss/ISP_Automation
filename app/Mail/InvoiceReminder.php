<?php

namespace App\Mail;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvoiceReminder extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Invoice $invoice) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your internet bill is due on '.$this->invoice->due_date->format('M j'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.invoice-reminder', with: [
            'subscription' => $this->invoice->subscription,
            'graceDays' => config('isp.grace_days'),
        ]);
    }
}
