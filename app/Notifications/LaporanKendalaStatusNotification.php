<?php

namespace App\Notifications;

use App\Enums\StatusLaporanEnum;
use App\Models\LaporanKendala;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LaporanKendalaStatusNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public LaporanKendala $laporan,
        public StatusLaporanEnum $status,
        public ?string $hasil = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $subjek = $this->status === StatusLaporanEnum::SELESAI
            ? 'Kendala Diselesaikan'
            : 'Laporan Kendala Ditutup';

        return [
            'title' => $subjek,
            'message' => "Laporan kendala {$this->laporan->nomor_laporan} berstatus ".ucwords(str_replace('_', ' ', $this->status->value)).'.'
                .($this->hasil ? " Hasil penanganan: {$this->hasil}" : ''),
            'type' => 'laporan_kendala',
            'action_url' => "/pelanggan/laporan-kendala/{$this->laporan->id}",
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $subjek = $this->status === StatusLaporanEnum::SELESAI
            ? 'Kendala Telah Diselesaikan'
            : 'Laporan Kendala Ditutup';

        return (new MailMessage)
            ->subject("{$subjek} — {$this->laporan->nomor_laporan}")
            ->view('emails.laporan-status', [
                'nama' => $notifiable->nama_lengkap,
                'nomorLaporan' => $this->laporan->nomor_laporan,
                'status' => $this->status->value,
                'hasil' => $this->hasil,
            ]);
    }
}
