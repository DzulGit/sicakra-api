<?php

namespace App\Notifications;

use App\Models\Pelanggan;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AkunDiaktifkanNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Pelanggan $pelanggan) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Akun Portal Pelanggan Anda Telah Aktif')
            ->view('emails.akun-diaktifkan', [
                'nama' => $notifiable->nama_lengkap,
                'nomorPelanggan' => $notifiable->nomor_pelanggan,
                'username' => $notifiable->username,
                'passwordDefault' => $notifiable->nomor_pelanggan,
            ]);
    }
}
