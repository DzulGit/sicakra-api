<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = config('services.frontend.url') . '/pelanggan/reset-password?token=' . $this->token . '&email=' . urlencode($notifiable->email);

        return (new MailMessage)
            ->subject('Reset Password Portal Pelanggan')
            ->view('emails.reset-password', [
                'nama' => $notifiable->nama_lengkap,
                'urlReset' => $url,
                'expires' => now()->addMinutes(config('auth.passwords.pelanggan.expire', 60))->format('d M Y H:i'),
            ]);
    }
}
