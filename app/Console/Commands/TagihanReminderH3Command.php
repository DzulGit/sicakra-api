<?php

namespace App\Console\Commands;

use App\Enums\StatusPembayaranEnum;
use App\Models\Tagihan;
use App\Notifications\TagihanJatuhTempoReminderNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TagihanReminderH3Command extends Command
{
    protected $signature = 'tagihan:reminder-h3';

    protected $description = 'Kirim email reminder tagihan yang jatuh tempo 3 hari lagi dan belum dibayar';

    public function handle(): void
    {
        $total = 0;

        Tagihan::query()
            ->where('status_pembayaran', StatusPembayaranEnum::BELUM_BAYAR->value)
            ->whereDate('tanggal_jatuh_tempo', now()->addDays(3)->toDateString())
            ->with('layananInternet.pelanggan')
            ->chunkById(100, function ($tagihan) use (&$total) {
                foreach ($tagihan as $t) {
                    $pelanggan = $t->layananInternet?->pelanggan;

                    if ($pelanggan?->email) {
                        $pelanggan->notify(new TagihanJatuhTempoReminderNotification($t));
                        $total++;
                    }
                }
            });

        Log::info("Reminder tagihan H-3 terkirim ke {$total} pelanggan.");
        $this->info("Reminder H-3 terkirim ke {$total} pelanggan.");
    }
}