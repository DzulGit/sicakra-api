<?php

namespace App\Jobs;

use App\Enums\StatusLayananEnum;
use App\Models\LayananInternet;
use App\Services\GenerateTagihanService;
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

    public function handle(GenerateTagihanService $generateTagihanService): void
    {
        LayananInternet::where('status', StatusLayananEnum::AKTIF)
            ->chunkById(100, function ($kumpulanLayanan) use ($generateTagihanService) {
                foreach ($kumpulanLayanan as $layanan) {
                    try {
                        $generateTagihanService->generateUntukLayanan(
                            $layanan,
                            $this->periodeBulan,
                            $this->periodeTahun
                        );
                    } catch (\Throwable $e) {
                        Log::error("Gagal generate tagihan untuk layanan #{$layanan->id}: {$e->getMessage()}");
                    }
                }
            });
    }
}