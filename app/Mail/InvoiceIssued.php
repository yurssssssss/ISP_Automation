<?php

namespace App\Mail;

use App\Models\Invoice;
use App\Services\Billing;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** "Your new bill": sent when billing:issue-invoices creates the invoice. */
class InvoiceIssued extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Invoice $invoice) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your internet bill for '.$this->invoice->period_start->format('F').' — due '.$this->invoice->due_date->format('M j'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.invoice-issued', with: [
            'subscription' => $this->invoice->subscription,
            'pausesOn' => app(Billing::class)->pausesOn($this->invoice),
        ]);
    }
}
