<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class ResellerEmailBerubahNotification extends Notification
{
    public function __construct(
        public bool $disetujui,
        public ?string $emailBaru = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        if ($this->disetujui) {
            return [
                'title' => 'Email Diperbarui',
                'message' => "Permintaan ganti email Anda disetujui. Email sekarang {$this->emailBaru}.",
                'type' => 'reseller',
                'action_url' => '/reseller/profil',
            ];
        }

        return [
            'title' => 'Ganti Email Ditolak',
            'message' => 'Permintaan ganti email Anda ditolak oleh Admin Operasional. Email Anda tetap tidak berubah.',
            'type' => 'reseller',
            'action_url' => '/reseller/profil',
        ];
    }
}