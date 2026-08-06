<?php

namespace App\Jobs;

use App\Services\SiklusPenagihanService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateTagihanMassalJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly int $tanggalBerjalan,
        private readonly int $periodeBulan,
        private readonly int $periodeTahun,
    ) {}

    public function handle(SiklusPenagihanService $siklusPenagihanService): void
    {
        // $periodeBulan/$periodeTahun lama bersifat global; dengan siklus
        // anniversary per-pelanggan, periode tagihan diambil dari jadwal masing-masing.
        // Nama argumen dipertahankan supaya job antrean lama tetap aman di-skip.
        if ($this->periodeBulan > 0 || $this->periodeTahun > 0) {
            Log::warning('GenerateTagihanMassalJob dengan periode eksplisit (legacy) diabaikan; prosesId via siklus.');
        }

        try {
            $diproses = $siklusPenagihanService->prosesHariIni();
            Log::info("GenerateTagihanMassalJob: {$diproses} layanan diproses hari ini.");
        } catch (\Throwable $e) {
            Log::error("Gagal GenerateTagihanMassalJob: {$e->getMessage()}");
        }
    }
}
