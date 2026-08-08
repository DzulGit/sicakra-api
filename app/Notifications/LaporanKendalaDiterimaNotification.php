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
        return ['mail'];
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
