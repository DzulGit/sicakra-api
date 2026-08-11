<?php

namespace App\Notifications;

use App\Models\PermohonanLayanan;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PendaftaranSelesaiNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public PermohonanLayanan $permohonan) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Pendaftaran Berhasil',
            'message' => "Pendaftaran Anda dengan nomor {$this->permohonan->nomor_permohonan} telah diterima. Tim kami akan memverifikasi data Anda.",
            'type' => 'pendaftaran',
            'action_url' => '/pelanggan/dashboard',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $permohonan = $this->permohonan->load('paketInternet');

        return (new MailMessage)
            ->subject("Pendaftaran Berhasil — {$permohonan->nomor_permohonan}")
            ->view('emails.pendaftaran-selesai', [
                'nama' => $notifiable->nama_lengkap,
                'nomorPermohonan' => $permohonan->nomor_permohonan,
                'paket' => $permohonan->paketInternet?->nama_paket ?? $permohonan->nama_paket_custom ?? '-',
            ]);
    }
}
