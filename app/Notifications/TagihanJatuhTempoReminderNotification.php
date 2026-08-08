<?php

namespace App\Notifications;

use App\Models\Tagihan;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TagihanJatuhTempoReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Tagihan $tagihan) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $tagihan = $this->tagihan->load('layananInternet.pelanggan');

        return (new MailMessage)
            ->subject("Reminder Tagihan — {$tagihan->nomor_tagihan}")
            ->view('emails.tagihan-jatuh-tempo', [
                'nama' => $notifiable->nama_lengkap,
                'nomorTagihan' => $tagihan->nomor_tagihan,
                'total' => number_format($tagihan->total_tagihan, 0, ',', '.'),
                'jatuhTempo' => $tagihan->tanggal_jatuh_tempo,
                'urlBayar' => $tagihan->xendit_invoice_url,
            ]);
    }
}
