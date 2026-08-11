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
        return ['mail', 'database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Tagihan Jatuh Tempo',
            'message' => "Tagihan {$this->tagihan->nomor_tagihan} sebesar Rp"
                .number_format($this->tagihan->total_tagihan, 0, ',', '.')
                ." jatuh tempo pada {$this->tagihan->tanggal_jatuh_tempo}. Segera lakukan pembayaran.",
            'type' => 'tagihan',
            'action_url' => "/pelanggan/tagihan/{$this->tagihan->id}",
        ];
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
