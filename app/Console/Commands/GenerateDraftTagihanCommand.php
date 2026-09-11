<?php

namespace App\Console\Commands;

use App\Enums\StatusLayananEnum;
use App\Models\LayananInternet;
use App\Services\GenerateTagihanService;
use Illuminate\Console\Command;

class GenerateDraftTagihanCommand extends Command
{
    protected $signature = 'tagihan:generate-draft {bulan?} {tahun?}';

    protected $description = 'Generate tagihan draft (belum_diterbitkan) untuk semua layanan aktif di periode tertentu. Default: bulan & tahun sekarang.';

    public function handle(GenerateTagihanService $generateTagihanService): int
    {
        $bulan = $this->argument('bulan') ?? (int) now()->month;
        $tahun = $this->argument('tahun') ?? (int) now()->year;

        $this->info("Generating draft tagihan untuk periode {$bulan}/{$tahun}...");

        $diproses = 0;
        $dibuat = 0;

        LayananInternet::where('status', StatusLayananEnum::AKTIF)
            ->with('pelanggan')
            ->chunkById(100, function ($kumpulan) use ($generateTagihanService, $bulan, $tahun, &$diproses, &$dibuat) {
                foreach ($kumpulan as $layanan) {
                    try {
                        $tagihan = $generateTagihanService->generateDraftUntukLayanan(
                            $layanan,
                            $bulan,
                            $tahun,
                        );

                        $diproses++;
                        if ($tagihan) {
                            $dibuat++;
                        }
                    } catch (\Throwable $e) {
                        $this->error("Gagal generate draft untuk layanan #{$layanan->id}: {$e->getMessage()}");
                    }
                }
            });

        $this->info("Selesai. {$diproses} layanan diproses, {$dibuat} tagihan draft dibuat.");
        $this->info("Tagihan draft akan muncul di halaman Terbitkan Tagihan untuk dipilih & diterbitkan oleh admin/reseller.");

        return Command::SUCCESS;
    }
}
