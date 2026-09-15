<?php

namespace Database\Seeders;

use App\Enums\PeranAdminEnum;
use App\Enums\StatusLaporanEnum;
use App\Enums\StatusPembayaranEnum;
use App\Enums\StatusPermohonanEnum;
use App\Enums\StatusTransaksiEnum;
use App\Models\Admin;
use App\Models\LaporanKendala;
use App\Models\LayananInternet;
use App\Models\MutasiSaldoKredit;
use App\Models\Pelanggan;
use App\Models\Pembayaran;
use App\Models\PembayaranTagihan;
use App\Models\PermohonanLayanan;
use App\Models\Tagihan;
use App\Notifications\LaporanKendalaBaruNotification;
use App\Notifications\PembayaranTagihanNotification;
use App\Notifications\PendaftarBaruNotification;
use App\Services\GenerateTagihanService;
use App\Services\GeneratorNomorService;
use App\Services\PembayaranAllocationService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * DemoSeeder — skenario tagihan & pembayaran. TIDAK membuat pelanggan/layanan
 * (itu master data di PelangganSeeder); hanya menata tagihan, pembayaran,
 * saldo kredit via GenerateTagihanService & PembayaranAllocationService agar
 * tidak menduplikasi logika bisnis. Waktu pembayaran di-backdate supaya
 * riwayat keuangan terlihat realistis.
 *
 * Skala nilai dikunci dari harga layanan: besar bayar dihitung dari
 * total_tagihan hasil service, bukan harga hardcoded.
 */
class DemoSeeder extends Seeder
{
    private GenerateTagihanService $generateTagihanService;

    private PembayaranAllocationService $allocationService;

    private Admin $keuangan;

    private Admin $adminUtama;

    public function run(): void
    {
        if (Pembayaran::exists()) {
            $this->command->warn('Data pembayaran demo sudah ada, dilewati.');

            return;
        }

        $this->generateTagihanService = app(GenerateTagihanService::class);
        $this->allocationService = app(PembayaranAllocationService::class);
        $this->keuangan = Admin::where('email', 'keuangan@sicakra.com')->firstOrFail();
        $this->adminUtama = Admin::where('email', 'admin@sicakra.com')->firstOrFail();

        $this->command->info('Skenario tagihan & pembayaran (15 pelanggan)');
        $this->seedSkenario();

        $this->command->info('Notifikasi unread untuk badge merah');
        $this->seedNotifikasi();

        $this->command->info('DemoSeeder selesai.');
    }

    private function seedSkenario(): void
    {
        // A. Lunas via tunai — 2 tagihan berurutan dibayar gabungan sekaligus.
        $budi = $this->pelanggan('budi');
        $layananBudi = $this->layanan($budi);
        $tagihanJul = $this->buatTagihan($layananBudi, $this->periode(-2));
        $tagihanAgu = $this->buatTagihan($layananBudi, $this->periode(-1));
        $this->bayarTunai(
            $budi,
            round((float) $tagihanJul->total_tagihan + (float) $tagihanAgu->total_tagihan, 2),
            [$tagihanJul->id, $tagihanAgu->id],
            $this->waktu(5, 11, 20, 5),
        );

        // B. Lunas via Xendit — tagihan multi-bulan (2 bulan).
        $siti = $this->pelanggan('siti');
        $layananSiti = $this->layanan($siti);
        $tagihanSiti = $this->buatTagihan($layananSiti, $this->periode(-3), 2);
        $this->bayarXendit(
            $siti,
            round((float) $tagihanSiti->total_tagihan, 2),
            [$tagihanSiti->id],
            $this->waktu(8, 16, 5, 42),
        );

        // C. Belum bayar sederhana.
        $agus = $this->pelanggan('agus');
        $this->buatTagihan($this->layanan($agus), $this->periode(0));

        // D. Cicilan parsial — 3 x 1/3 nominal hingga lunas.
        $dewi = $this->pelanggan('dewi');
        $tagihanDewi = $this->buatTagihan($this->layanan($dewi), $this->periode(-1));
        $cicilanDewi = round((float) $tagihanDewi->total_tagihan / 3, 2);
        $this->bayarTunai($dewi, $cicilanDewi, [$tagihanDewi->id], $this->waktu(20, 9, 15, 32));
        $this->bayarTunai($dewi, $cicilanDewi, [$tagihanDewi->id], $this->waktu(12, 13, 42, 10));
        $this->bayarTunai($dewi, $cicilanDewi, [$tagihanDewi->id], $this->waktu(4, 10, 22, 48));

        // E. Dibayar sebagian — sisa tagihan > 0.
        $wahyu = $this->pelanggan('wahyu');
        $tagihanWahyu = $this->buatTagihan($this->layanan($wahyu), $this->periode(0));
        $cicilanWahyu = round((float) $tagihanWahyu->total_tagihan / 3, 2);
        $this->bayarTunai($wahyu, $cicilanWahyu, [$tagihanWahyu->id], $this->waktu(10, 9, 5, 0));
        $this->bayarTunai($wahyu, $cicilanWahyu, [$tagihanWahyu->id], $this->waktu(2, 14, 30, 0));

        // F. Pembayaran gabungan antar-layanan: 2 dari 3 tagihan dibayar sekali.
        $nia = $this->pelanggan('nia');
        $layananNiaC = $this->layanan($nia, 2);
        $tagihanNiaA = $this->buatTagihan($this->layanan($nia, 0), $this->periode(-2));
        $tagihanNiaB = $this->buatTagihan($this->layanan($nia, 1), $this->periode(-1));
        $tagihanNiaC = $this->buatTagihan($layananNiaC, $this->periode(0));
        $this->bayarTunai(
            $nia,
            round((float) $tagihanNiaA->total_tagihan + (float) $tagihanNiaB->total_tagihan, 2),
            [$tagihanNiaA->id, $tagihanNiaB->id],
            $this->waktu(3, 13, 25, 12),
        );
        // Draft tagihan bulan depan (belum_diterbitkan) untuk layanan C.
        $this->buatTagihanDraft($layananNiaC, $this->periode(1));

        // G. Deposit: kelebihan bayar -> saldo kredit 100k.
        $sri = $this->pelanggan('sri');
        $tagihanSri = $this->buatTagihan($this->layanan($sri), $this->periode(0));
        $this->bayarTunai(
            $sri,
            round((float) $tagihanSri->total_tagihan + 100000, 2),
            [$tagihanSri->id],
            $this->waktu(6, 9, 40, 8),
        );

        // H. Deposit 100k (bronze 150k dibayar 250k), lalu dipakai melunasi
        //    sebagian tagihan 300k -> sisa 200k.
        $joko = $this->pelanggan('joko');
        $tagihanBronze = $this->buatTagihan($this->layanan($joko, 0), $this->periode(-3));
        $tagihanToko = $this->buatTagihan($this->layanan($joko, 1), $this->periode(0));
        $this->bayarTunai(
            $joko,
            round((float) $tagihanBronze->total_tagihan + 100000, 2),
            [$tagihanBronze->id],
            $this->waktu(18, 10, 15, 0),
        );
        $this->pakaiSaldoKredit($joko, [$tagihanToko->id], $this->waktu(1, 11, 0, 0));

        // I. Deposit 300k (100k dibayar 400k), lunas tagihan berikutnya via
        //    saldo kredit, sisa saldo 200k + tagihan draft layanan kedua.
        $rina = $this->pelanggan('rina');
        $tagihanRina1 = $this->buatTagihan($this->layanan($rina, 0), $this->periode(-3));
        $tagihanRina2 = $this->buatTagihan($this->layanan($rina, 0), $this->periode(0));
        $layananRinaCadangan = $this->layanan($rina, 1);
        $this->bayarTunai(
            $rina,
            round((float) $tagihanRina1->total_tagihan + 300000, 2),
            [$tagihanRina1->id],
            $this->waktu(22, 9, 30, 0),
        );
        $this->pakaiSaldoKredit($rina, [$tagihanRina2->id], $this->waktu(2, 10, 0, 0));
        $this->buatTagihanDraft($layananRinaCadangan, $this->periode(1));

        // J. Pembayaran provider xendit PENDING & GAGAL — tagihan tetap belum bayar.
        $fajar = $this->pelanggan('fajar');
        $tagihanFajar = $this->buatTagihan($this->layanan($fajar), $this->periode(0));
        $this->buatPembayaranGagal($fajar, (float) $tagihanFajar->total_tagihan, $this->waktu(7, 8, 10, 0));
        $this->buatPembayaranPending($fajar, (float) $tagihanFajar->total_tagihan, $this->waktu(1, 9, 15, 0));

        // K. Titik langganan dengan kelebihan bayar 50k (saldo kredit 50k).
        $putra = $this->pelanggan('putra');
        $tagihanPutra = $this->buatTagihan($this->layanan($putra), $this->periode(0));
        $this->bayarTunai(
            $putra,
            round((float) $tagihanPutra->total_tagihan + 50000, 2),
            [$tagihanPutra->id],
            $this->waktu(4, 15, 45, 0),
        );

        // L. Belum bayar di bawah reseller.
        $ilham = $this->pelanggan('ilham');
        $this->buatTagihan($this->layanan($ilham), $this->periode(0));

        // M. Lunas + kelebihan bayar 50k di bawah reseller.
        $bagas = $this->pelanggan('bagas');
        $tagihanBagas = $this->buatTagihan($this->layanan($bagas), $this->periode(0));
        $this->bayarTunai(
            $bagas,
            round((float) $tagihanBagas->total_tagihan + 50000, 2),
            [$tagihanBagas->id],
            $this->waktu(9, 14, 10, 0),
        );

        // N. Pelanggan baru (sulton, hendra): layanan aktif, tanpa tagihan.
        //    Dibuat oleh PelangganSeeder — cukup hadir di sini tanpa aksi.
    }

    private function seedNotifikasi(): void
    {
        $permohonan = PermohonanLayanan::where('status', StatusPermohonanEnum::MENUNGGU_VERIFIKASI)->first();
        $laporan = LaporanKendala::where('status', StatusLaporanEnum::MENUNGGU)->first();
        $pembayaran = Pembayaran::where('status', StatusTransaksiEnum::BERHASIL)->first();

        if ($permohonan) {
            Notification::send(
                Admin::where('status_aktif', true)
                    ->whereIn('peran', [PeranAdminEnum::OPERASIONAL, PeranAdminEnum::SUPER_ADMIN])
                    ->get(),
                new PendaftarBaruNotification($permohonan)
            );
        }

        if ($laporan) {
            Notification::send(
                Admin::where('status_aktif', true)
                    ->whereIn('peran', [PeranAdminEnum::OPERASIONAL, PeranAdminEnum::SUPER_ADMIN])
                    ->get(),
                new LaporanKendalaBaruNotification($laporan)
            );
        }

        if ($pembayaran) {
            $tagihan = $pembayaran->alokasiTagihan()->first()?->tagihan;
            if ($tagihan) {
                Notification::send(
                    Admin::where('status_aktif', true)
                        ->whereIn('peran', [PeranAdminEnum::KEUANGAN, PeranAdminEnum::SUPER_ADMIN])
                        ->get(),
                    new PembayaranTagihanNotification($tagihan, $pembayaran)
                );
            }
        }

        DB::table('notifications')
            ->where('notifiable_id', $this->adminUtama->id)
            ->whereNull('read_at')
            ->whereBetween('created_at', [now()->subMinutes(10), now()])
            ->update(['created_at' => now()->subHours(6)->subMinutes(30)]);
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    /** [bulan, tahun] yang digeser sejumlah bulan dari hari ini. */
    private function periode(int $offset): array
    {
        $tanggal = Carbon::today()->addMonthsNoOverflow($offset);

        return [$tanggal->month, $tanggal->year];
    }

    private function pelanggan(string $username): Pelanggan
    {
        return Pelanggan::where('username', $username)->firstOrFail();
    }

    private function layanan(Pelanggan $pelanggan, int $urutan = 0): LayananInternet
    {
        $layanan = $pelanggan->layananInternet()->orderBy('id')->get()[$urutan] ?? null;

        if (! $layanan) {
            throw new RuntimeException("Layanan urutan {$urutan} untuk {$pelanggan->username} tidak ditemukan.");
        }

        return $layanan;
    }

    private function buatTagihan(LayananInternet $layanan, array $periode, int $jumlahBulan = 1): Tagihan
    {
        [$bulan, $tahun] = $periode;

        $tagihan = $this->generateTagihanService->generateUntukLayanan($layanan, $bulan, $tahun, $jumlahBulan);
        if (! $tagihan) {
            throw new RuntimeException("Gagal generate tagihan {$bulan}/{$tahun} untuk layanan #{$layanan->id}.");
        }

        return $tagihan;
    }

    private function buatTagihanDraft(LayananInternet $layanan, array $periode): Tagihan
    {
        [$bulan, $tahun] = $periode;

        $tagihan = $this->generateTagihanService->generateDraftUntukLayanan($layanan, $bulan, $tahun);
        if (! $tagihan) {
            throw new RuntimeException("Gagal generate tagihan draft {$bulan}/{$tahun} untuk layanan #{$layanan->id}.");
        }

        return $tagihan;
    }

    private function bayarTunai(Pelanggan $pelanggan, float $jumlah, array $tagihanIds, Carbon $waktu): Pembayaran
    {
        $pembayaran = $this->allocationService->buatPembayaranTunai($pelanggan, $jumlah, [
            'metode_pembayaran' => 'tunai',
            'dibayar_oleh' => $this->keuangan->nama_lengkap,
            'tagihan_terpilih' => array_map('intval', $tagihanIds),
        ]);

        $this->tundaWaktuPembayaran($pembayaran, $waktu);

        return $pembayaran;
    }

    private function bayarXendit(Pelanggan $pelanggan, float $jumlah, array $tagihanIds, Carbon $waktu): Pembayaran
    {
        $pembayaran = $this->buktikanPembayaranProvider($pelanggan, $jumlah, $tagihanIds);
        $this->tundaWaktuPembayaran($pembayaran, $waktu);

        return $pembayaran;
    }

    /** Pembayaran provider yang diselesaikan lewat selesaikanPembayaran (alur webhook). */
    private function buktikanPembayaranProvider(Pelanggan $pelanggan, float $jumlah, array $tagihanIds): Pembayaran
    {
        $pembayaran = Pembayaran::create([
            'pelanggan_id' => $pelanggan->id,
            'tagihan_id' => null,
            'metode_pembayaran' => 'xendit',
            'provider' => 'xendit',
            'provider_reference' => 'INV-'.strtoupper(Str::random(12)),
            'provider_external_id' => 'sicakra-'.Str::lower(Str::random(14)),
            'provider_status' => 'PAID',
            'jumlah_dibayar' => round($jumlah, 2),
            'tagihan_terpilih' => array_map('intval', $tagihanIds),
            'status' => StatusTransaksiEnum::BERHASIL,
        ]);

        $this->allocationService->selesaikanPembayaran($pembayaran->refresh());

        return $pembayaran->refresh();
    }

    /** Pembayaran PENDING yang belum pernah dikonfirmasi webhook. */
    private function buatPembayaranPending(Pelanggan $pelanggan, float $jumlah, Carbon $waktu): Pembayaran
    {
        $pembayaran = Pembayaran::create([
            'pelanggan_id' => $pelanggan->id,
            'tagihan_id' => null,
            'metode_pembayaran' => 'xendit',
            'provider' => 'xendit',
            'provider_reference' => 'INV-'.strtoupper(Str::random(12)),
            'provider_external_id' => 'sicakra-'.Str::lower(Str::random(14)),
            'payment_url' => 'https://checkout.xendit.co/web/'.Str::lower(Str::random(24)),
            'provider_status' => 'active',
            'provider_expires_at' => now()->addDays(3),
            'jumlah_dibayar' => round($jumlah, 2),
            'tagihan_terpilih' => null,
            'status' => StatusTransaksiEnum::PENDING,
        ]);

        Pembayaran::whereKey($pembayaran->id)->update([
            'created_at' => $waktu,
            'updated_at' => $waktu,
        ]);

        return $pembayaran->refresh();
    }

    /** Pembayaran provider yang berakhir GAGAL. */
    private function buatPembayaranGagal(Pelanggan $pelanggan, float $jumlah, Carbon $waktu): Pembayaran
    {
        $pembayaran = Pembayaran::create([
            'pelanggan_id' => $pelanggan->id,
            'tagihan_id' => null,
            'metode_pembayaran' => 'xendit',
            'provider' => 'xendit',
            'provider_reference' => 'INV-'.strtoupper(Str::random(12)),
            'provider_external_id' => 'sicakra-'.Str::lower(Str::random(14)),
            'provider_status' => 'FAILED',
            'jumlah_dibayar' => round($jumlah, 2),
            'tagihan_terpilih' => null,
            'status' => StatusTransaksiEnum::GAGAL,
        ]);

        Pembayaran::whereKey($pembayaran->id)->update([
            'created_at' => $waktu,
            'updated_at' => $waktu,
        ]);

        return $pembayaran->refresh();
    }

    /** Pemakaian saldo kredit untuk tagihan tertentu (alur gunakanSaldoKredit). */
    private function pakaiSaldoKredit(Pelanggan $pelanggan, array $tagihanIds, Carbon $waktu): void
    {
        $this->allocationService->gunakanSaldoKredit($pelanggan, array_map('intval', $tagihanIds));

        MutasiSaldoKredit::where('pelanggan_id', $pelanggan->id)
            ->whereIn('tagihan_id', $tagihanIds)
            ->where('jenis', 'pemakaian')
            ->update(['created_at' => $waktu, 'updated_at' => $waktu]);

        foreach ($tagihanIds as $tagihanId) {
            $tagihan = Tagihan::find($tagihanId);
            if ($tagihan?->status_pembayaran === StatusPembayaranEnum::SUDAH_BAYAR) {
                Tagihan::whereKey($tagihanId)->update(['dibayar_pada' => $waktu, 'updated_at' => $waktu]);
            }
        }
    }

    /** Backdate pembayaran & seluruh efeknya (alokasi, mutasi kredit, tagihan lunas). */
    private function tundaWaktuPembayaran(Pembayaran $pembayaran, Carbon $waktu): void
    {
        Pembayaran::whereKey($pembayaran->id)->update([
            'dibayar_pada' => $waktu,
            'created_at' => $waktu,
            'updated_at' => $waktu,
        ]);

        PembayaranTagihan::where('pembayaran_id', $pembayaran->id)->update([
            'created_at' => $waktu,
            'updated_at' => $waktu,
        ]);

        MutasiSaldoKredit::where('pembayaran_id', $pembayaran->id)->update([
            'created_at' => $waktu,
            'updated_at' => $waktu,
        ]);

        foreach (PembayaranTagihan::where('pembayaran_id', $pembayaran->id)->pluck('tagihan_id') as $tagihanId) {
            $tagihan = Tagihan::find($tagihanId);
            if ($tagihan?->status_pembayaran === StatusPembayaranEnum::SUDAH_BAYAR) {
                Tagihan::whereKey($tagihanId)->update(['dibayar_pada' => $waktu, 'updated_at' => $waktu]);
            }
        }
    }

    private function waktu(int $hariLalu, int $jam, int $menit, int $detik): Carbon
    {
        return Carbon::now()->subDays($hariLalu)->setTime($jam, $menit, $detik);
    }
}