<?php

namespace App\Notifications;

use App\Models\Tagihan;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TagihanDibuatNotification extends Notification implements ShouldQueue
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
            'title' => 'Tagihan Baru',
            'message' => "Tagihan {$this->tagihan->nomor_tagihan} sebesar Rp"
                .number_format($this->tagihan->total_tagihan, 0, ',', '.')
                ." untuk periode {$this->tagihan->periode_akhir_bulan} telah dibuat.",
            'type' => 'tagihan',
            'action_url' => "/pelanggan/tagihan/{$this->tagihan->id}",
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $tagihan = $this->tagihan->load('layananInternet.pelanggan');

        return (new MailMessage)
            ->subject("Tagihan Baru — {$tagihan->nomor_tagihan}")
            ->view('emails.tagihan-dibuat', [
                'nama' => $notifiable->nama_lengkap,
                'nomorTagihan' => $tagihan->nomor_tagihan,
                'nomorLayanan' => $tagihan->layananInternet?->nomor_layanan ?? '-',
                'paket' => $tagihan->nama_paket_snapshot,
                'periode' => $tagihan->periode_akhir_bulan,
                'total' => number_format($tagihan->total_tagihan, 0, ',', '.'),
                'jatuhTempo' => $tagihan->tanggal_jatuh_tempo,
                'urlBayar' => $tagihan->xendit_invoice_url,
            ]);
    }
}
