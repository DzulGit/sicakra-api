<?php

namespace App\Notifications;

use App\Models\LaporanKendala;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LaporanKendalaDiterimaNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public LaporanKendala $laporan) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Laporan Diterima',
            'message' => "Laporan kendala {$this->laporan->nomor_laporan} telah diterima dan sedang kami proses.",
            'type' => 'laporan_kendala',
            'action_url' => "/pelanggan/laporan-kendala/{$this->laporan->id}",
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Laporan Kendala Diterima — {$this->laporan->nomor_laporan}")
            ->view('emails.laporan-diterima', [
                'nama' => $notifiable->nama_lengkap,
                'nomorLaporan' => $this->laporan->nomor_laporan,
                'kategori' => $this->laporan->kategori_kendala,
            ]);
    }
}
