<?php

namespace App\Notifications;

use App\Enums\StatusPermohonanEnum;
use App\Models\PermohonanLayanan;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PermohonanStatusNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public PermohonanLayanan $permohonan,
        public StatusPermohonanEnum $status,
        public ?string $catatan = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $subjek = match ($this->status) {
            StatusPermohonanEnum::DITERIMA => 'Permohonan Diterima',
            StatusPermohonanEnum::DIJADWALKAN => 'Jadwal Pekerjaan Dikonfirmasi',
            StatusPermohonanEnum::DITOLAK => 'Permohonan Ditolak',
            StatusPermohonanEnum::PERLU_REVISI => 'Permohonan Perlu Revisi',
            default => 'Status Permohonan Berubah',
        };

        return [
            'title' => $subjek,
            'message' => "Permohonan {$this->permohonan->nomor_permohonan} kini berstatus {$this->status->label()}."
                .($this->catatan ? " Catatan: {$this->catatan}" : ''),
            'type' => 'permohonan',
            'action_url' => '/pelanggan/dashboard',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $subjek = match ($this->status) {
            StatusPermohonanEnum::DITERIMA => 'Permohonan Diterima',
            StatusPermohonanEnum::DIJADWALKAN => 'Jadwal Pekerjaan Dikonfirmasi',
            StatusPermohonanEnum::DITOLAK => 'Permohonan Ditolak',
            StatusPermohonanEnum::PERLU_REVISI => 'Permohonan Perlu Revisi',
            default => 'Status Permohonan Berubah',
        };

        return (new MailMessage)
            ->subject("{$subjek} — {$this->permohonan->nomor_permohonan}")
            ->view('emails.permohonan-status', [
                'nama' => $notifiable->nama_lengkap,
                'nomorPermohonan' => $this->permohonan->nomor_permohonan,
                'status' => $this->status->label(),
                'catatan' => $this->catatan,
            ]);
    }
}
