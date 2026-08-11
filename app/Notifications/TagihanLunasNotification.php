<?php

namespace App\Notifications;

use App\Models\Tagihan;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TagihanLunasNotification extends Notification implements ShouldQueue
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
            'title' => 'Pembayaran Diterima',
            'message' => "Pembayaran tagihan {$this->tagihan->nomor_tagihan} sebesar Rp"
                .number_format($this->tagihan->total_tagihan, 0, ',', '.')
                .' telah kami terima. Terima kasih!',
            'type' => 'pembayaran',
            'action_url' => "/pelanggan/tagihan/{$this->tagihan->id}",
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $tagihan = $this->tagihan->load('layananInternet.pelanggan');

        return (new MailMessage)
            ->subject("Pembayaran Diterima — {$tagihan->nomor_tagihan}")
            ->view('emails.tagihan-lunas', [
                'nama' => $notifiable->nama_lengkap,
                'nomorTagihan' => $tagihan->nomor_tagihan,
                'total' => number_format($tagihan->total_tagihan, 0, ',', '.'),
                'dibayarPada' => $tagihan->dibayar_pada,
            ]);
    }
}
