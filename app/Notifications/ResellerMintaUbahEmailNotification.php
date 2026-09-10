<?php

namespace App\Notifications;

use App\Models\Admin;
use Illuminate\Notifications\Notification;

class ResellerMintaUbahEmailNotification extends Notification
{
    public function __construct(
        public Admin $reseller,
        public string $emailBaru,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Permintaan Ganti Email Reseller',
            'message' => "{$this->reseller->nama_lengkap} meminta ganti email menjadi {$this->emailBaru} dan menunggu persetujuan.",
            'type' => 'reseller',
            'action_url' => '/admin/operasional/reseller',
        ];
    }
}