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
        return ['mail', 'database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Akun Diaktifkan',
            'message' => 'Akun portal pelanggan Anda telah aktif. Silakan masuk dengan nomor pelanggan dan kata sandi default Anda.',
            'type' => 'akun',
            'action_url' => '/pelanggan/masuk',
        ];
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
