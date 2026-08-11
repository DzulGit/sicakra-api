<?php

namespace App\Notifications;

use App\Models\LaporanKendala;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LaporanKendalaDitugaskanNotification extends Notification implements ShouldQueue
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
            'title' => 'Laporan Ditugaskan',
            'message' => "Laporan kendala {$this->laporan->nomor_laporan} ({$this->laporan->kategori_kendala}) ditugaskan kepada Anda.",
            'type' => 'laporan_kendala',
            'action_url' => "/admin/teknisi/laporan-kendala/{$this->laporan->id}",
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $laporan = $this->laporan->load('layananInternet.pelanggan');

        return (new MailMessage)
            ->subject("Laporan Kendala Ditugaskan — {$laporan->nomor_laporan}")
            ->view('emails.laporan-ditugaskan', [
                'nama' => $notifiable->nama_lengkap,
                'nomorLaporan' => $laporan->nomor_laporan,
                'kategori' => $laporan->kategori_kendala,
                'deskripsi' => $laporan->deskripsi,
                'pelanggan' => $laporan->layananInternet?->pelanggan?->nama_lengkap ?? '-',
            ]);
    }
}
