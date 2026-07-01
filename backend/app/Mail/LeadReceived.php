<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Lead;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Письмо банку с данными новой заявки.
 *
 * Отправляется на banks.email соответствующего банка (всегда, независимо от
 * is_partner — backend.md §3.2). Ставится в очередь после успешной записи лида;
 * сбой доставки не теряет лид и не меняет HTTP-код (best-effort).
 */
class LeadReceived extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly Lead $lead)
    {
    }

    public function envelope(): Envelope
    {
        $productName = $this->lead->product?->name_ru
            ?? $this->lead->product?->name_tg
            ?? ('#'.$this->lead->product_id);

        return new Envelope(
            subject: 'Новая заявка — '.$productName,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.leads.received',
            with: [
                'lead' => $this->lead,
                'product' => $this->lead->product,
                'bank' => $this->lead->bank,
            ],
        );
    }
}
