<?php

namespace Database\Seeders;

use App\Enums\PeranAdminEnum;
use App\Enums\StatusLayananEnum;
use App\Enums\StatusPembayaranEnum;
use App\Enums\StatusTransaksiEnum;
use App\Enums\TipePaketEnum;
use App\Models\Admin;
use App\Models\LayananInternet;
use App\Models\MutasiSaldoKredit;
use App\Models\PaketInternet;
use App\Models\Pelanggan;
use App\Models\Pembayaran;
use App\Models\PembayaranTagihan;
use App\Models\Tagihan;
use App\Services\GenerateTagihanService;
use App\Services\GeneratorNomorService;
use App\Services\PembayaranAllocationService;
use App\Services\SiklusPenagihanService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * FinanceScenarioSeeder — dataset besar skenario keuangan untuk presentasi.
 *
 * Membuat pelanggan, layanan, tagihan, pembayaran, dan mutasi saldo kredit
 * yang realistis (volume tinggi) di atas master data PelangganSeeder dan
 * skenario kecil DemoSeeder. Semua tagihan & pembayaran dibuat lewat
 * GenerateTagihanService / PembayaranAllocationService agar tidak menduplikasi
 * logika bisnis (alokasi, kelebihan bayar → kredit, pemakaian saldo).
 *
 * Idempotent: nilai nominal dihitung dari total_tagihan hasil service,
 * bukan harga hardcoded.
 */
class FinanceScenarioSeeder extends Seeder
{
    private GenerateTagihanService $generate;

    private PembayaranAllocationService $allocation;

    private SiklusPenagihanService $siklus;

    private GeneratorNomorService $generator;

    private Admin $keuangan;

    private int $nomorHp = 10000000;

    private int $nik = 3600000000000000;

    public function run(): void
    {
        $this->generate = app(GenerateTagihanService::class);
        $this->allocation = app(PembayaranAllocationService::class);
        $this->siklus = app(SiklusPenagihanService::class);
        $this->generator = app(GeneratorNomorService::class);
        $this->keuangan = Admin::where('email', 'keuangan@sicakra.com')->firstOrFail();

        // Lanjut dari data yang sudah ada agar re-run (jalankan ulang seeder)
        // tidak bentrok pada nomor HP / NIK.
        $this->nomorHp = (int) (Pelanggan::query()
            ->selectRaw("MAX(CAST(SUBSTRING(nomor_hp FROM 5) AS BIGINT)) AS mx")
            ->where('nomor_hp', 'LIKE', '0812%')
            ->value('mx')) ?: 10000000;
        $this->nik = (int) (Pelanggan::query()
            ->selectRaw('MAX(CAST(nik AS BIGINT)) AS mx')
            ->value('mx')) ?: 3600000000000000;

        $bronze = $this->paket('Paket Bronze');
        $silver = $this->paket('Paket Silver');
        $gold = $this->paket('Paket Gold');
        $platinum = $this->paket('Paket Platinum');
        $diamond = $this->paket('Paket Diamond');
        $promoKeluarga = $this->paket('Paket Family Promo 1 Bulan');
        $resellerNet = $this->paket('Reseller Nusantara 30 Mbps');

        // Dataset besar hanya dibuat sekali (guard global). Skenario baru
        // (seedStatusBerjalan, seedDraftReseller) idempotent per username
        // sehingga bisa dijalankan ulang tanpa wipe database.
        $sudahAda = Pelanggan::where('username', 'andri-prasetyo')->exists();

        if (! $sudahAda) {
            $this->command->info('Skenario keuangan referensi (non-reseller & reseller utama)');
            $this->seedSkenarioReferensi($bronze, $silver, $gold, $platinum, $diamond, $promoKeluarga, $resellerNet);

            $this->command->info('Reseller baru + skenario volumenya');
            $this->seedResellerBaru();

            $this->command->info('Pelanggan rumah tangga langsung (volume)');
            $this->seedPasokan([
                ['nama' => 'Gilang Ramadhan', 'paket' => $silver, 'bulan' => 8, 'pola' => 'cash'],
                ['nama' => 'Rina Wijaya', 'paket' => $bronze, 'bulan' => 7, 'pola' => 'over'],
                ['nama' => 'Adi Susanto', 'paket' => $gold, 'bulan' => 9, 'pola' => 'cash'],
                ['nama' => 'Mega Lestari', 'paket' => $bronze, 'bulan' => 6, 'pola' => 'xendit'],
                ['nama' => 'Rudi Hartono', 'paket' => $silver, 'bulan' => 7, 'pola' => 'over'],
                ['nama' => 'Dewi Anggraini', 'paket' => $bronze, 'bulan' => 8, 'pola' => 'over'],
                ['nama' => 'Hendra Gunawan', 'paket' => $gold, 'bulan' => 6, 'pola' => 'over'],
                ['nama' => 'Sri Wahyuni', 'paket' => $bronze, 'bulan' => 7, 'pola' => 'over'],
                ['nama' => 'Bayu Pratama', 'paket' => $silver, 'bulan' => 8, 'pola' => 'cash'],
                ['nama' => 'Ratna Sari', 'paket' => $bronze, 'bulan' => 6, 'pola' => 'tunggak'],
                ['nama' => 'Agus Salim', 'paket' => $silver, 'bulan' => 9, 'pola' => 'cash'],
                ['nama' => 'Zahra Nuraini', 'paket' => $bronze, 'bulan' => 7, 'pola' => 'over'],
                ['nama' => 'Fajar Nugroho', 'paket' => $gold, 'bulan' => 8, 'pola' => 'xendit'],
                ['nama' => 'Cici Melati', 'paket' => $bronze, 'bulan' => 6, 'pola' => 'over'],
                ['nama' => 'Rizky Hidayat', 'paket' => $silver, 'bulan' => 7, 'pola' => 'cicil'],
                ['nama' => 'Sinta Nurhaliza', 'paket' => $bronze, 'bulan' => 8, 'pola' => 'over'],
            ], null, $this->keuangan->nama_lengkap);

            $this->command->info('Draft tagihan bulan depan & notifikasi tambahan');
            $this->seedPenutup($silver, $gold);
        } else {
            $this->command->warn('Dataset finance lama ditemukan — menambahkan skenario baru saja.');
        }

        $this->command->info('Status keuangan berjalan: sedang cicil & deposit (admin utama + reseller)');
        $this->seedStatusBerjalan($bronze, $silver, $resellerNet);

        $this->command->info('Draft tagihan reseller untuk halaman Terbitkan Tagihan');
        $this->seedDraftReseller();

        $this->command->info('FinanceScenarioSeeder selesai.');
    }

    // ------------------------------------------------------------------
    // Skenario referensi
    // ------------------------------------------------------------------

    private function seedSkenarioReferensi(
        PaketInternet $bronze,
        PaketInternet $silver,
        PaketInternet $gold,
        PaketInternet $platinum,
        PaketInternet $diamond,
        PaketInternet $promoKeluarga,
        PaketInternet $resellerNet,
    ): void {
        // Andri — lunas konsisten 13 bulan via tunai.
        $andri = $this->buatPelanggan('Andri Prasetyo', 'andri-prasetyo');
        $lAndri = $this->buatLayanan($andri, $silver, $this->waktuBulan(-12, 3));
        for ($x = -12; $x <= 0; $x++) {
            $t = $this->buatTagihan($lAndri, $x);
            $this->bayarTunai($andri, (float) $t->total_tagihan, [$t->id], $this->waktuBulan($x, 15, 9, 30));
        }

        // Sari — lunas rutin 13 bulan via Xendit.
        $sari = $this->buatPelanggan('Sari Dewi', 'sari-dewi');
        $lSari = $this->buatLayanan($sari, $gold, $this->waktuBulan(-12, 5));
        for ($x = -12; $x <= 0; $x++) {
            $t = $this->buatTagihan($lSari, $x);
            $this->bayarXendit($sari, (float) $t->total_tagihan, [$t->id], $this->waktuBulan($x, 18, 20, 15));
        }

        // Dodi — baru, 2 bulan lunas, bulan berjalan belum + ada pembayaran pending.
        $dodi = $this->buatPelanggan('Dodi Kurniawan', 'dodi-kurniawan');
        $lDodi = $this->buatLayanan($dodi, $platinum, $this->waktuBulan(-2, 10));
        for ($x = -2; $x <= 0; $x++) {
            $t = $this->buatTagihan($lDodi, $x);
            if ($x < 0) {
                $this->bayarXendit($dodi, (float) $t->total_tagihan, [$t->id], $this->waktuBulan($x, 12, 10, 5));
            }
        }
        $this->buatPending($dodi, (float) $lDodi->tagihan()->latest('periode_tahun')->latest('periode_bulan')->first()->total_tagihan, $this->waktuHariLalu(1, 9, 0));

        // Maya — 2 layanan, dibayar gabungan via Xendit tiap bulan; bulan berjalan belum.
        $maya = $this->buatPelanggan('Maya Astuti', 'maya-astuti');
        $lMaya1 = $this->buatLayanan($maya, $bronze, $this->waktuBulan(-11, 7));
        $lMaya2 = $this->buatLayanan($maya, $silver, $this->waktuBulan(-8, 9));
        for ($x = -11; $x <= 0; $x++) {
            $tagihan = [];
            $t1 = $this->buatTagihan($lMaya1, $x);
            $tagihan[] = $t1->id;
            $t2 = null;
            if ($x >= -8) {
                $t2 = $this->buatTagihan($lMaya2, $x);
                $tagihan[] = $t2->id;
            }
            if ($x < 0) {
                $jumlah = (float) $t1->total_tagihan + ($t2 ? (float) $t2->total_tagihan : 0);
                $this->bayarXendit($maya, $jumlah, $tagihan, $this->waktuBulan($x, 16, 11, 20));
            }
        }

        // Teguh — cicilan 2 langkah tiap bulan sampai lunas; bulan berjalan belum dibayar.
        $teguh = $this->buatPelanggan('Teguh Prakoso', 'teguh-prakoso');
        $lTeguh = $this->buatLayanan($teguh, $silver, $this->waktuBulan(-3, 12));
        for ($x = -3; $x <= 0; $x++) {
            $t = $this->buatTagihan($lTeguh, $x);
            if ($x < 0) {
                $separuh = round((float) $t->total_tagihan / 2, 2);
                $this->bayarTunai($teguh, $separuh, [$t->id], $this->waktuBulan($x, 5, 9, 5));
                $this->bayarTunai($teguh, $separuh, [$t->id], $this->waktuBulan($x, 20, 13, 40));
            }
        }

        // Lina — tunggakan penuh di semua bulan + pembayaran pending yang nyangkut.
        $lina = $this->buatPelanggan('Lina Maharani', 'lina-maharani');
        $lLina = $this->buatLayanan($lina, $silver, $this->waktuBulan(-3, 8));
        for ($x = -3; $x <= 0; $x++) {
            $t = $this->buatTagihan($lLina, $x);
            if ($x === 0) {
                $this->buatPending($lina, (float) $t->total_tagihan, $this->waktuHariLalu(2, 14, 30));
            }
        }

        // Reza — 2 bulan tertunggak, 1 bulan dibayar sebagian, bulan berjalan belum.
        $reza = $this->buatPelanggan('Reza Firmansyah', 'reza-firmansyah');
        $lReza = $this->buatLayanan($reza, $bronze, $this->waktuBulan(-3, 6));
        $tReza = [];
        for ($x = -3; $x <= 0; $x++) {
            $tReza[$x] = $this->buatTagihan($lReza, $x);
        }
        $this->bayarTunai($reza, round((float) $tReza[-1]->total_tagihan / 2, 2), [$tReza[-1]->id], $this->waktuBulan(-1, 10, 9, 0));

        // Intan — kelebihan bayar 100rb jadi kredit, lalu kredit dipakai + pelunasan kecil.
        $intan = $this->buatPelanggan('Intan Permata', 'intan-permata');
        $lIntan = $this->buatLayanan($intan, $bronze, $this->waktuBulan(-1, 4));
        $tIntan0 = $this->buatTagihan($lIntan, -1);
        $tIntanSekarang = $this->buatTagihan($lIntan, 0);
        $this->bayarTunai($intan, (float) $tIntan0->total_tagihan + 100000, [$tIntan0->id], $this->waktuBulan(-1, 20, 15, 0));
        $this->pakaiKredit($intan, [$tIntanSekarang->id], $this->waktuBulan(0, 8, 10, 0));
        $this->bayarTunai($intan, round((float) $tIntanSekarang->total_tagihan - 100000, 2), [$tIntanSekarang->id], $this->waktuBulan(0, 9, 11, 30));

        // Yoga — deposit 150rb, dipakai penuh bulan berjalan (saldo habis).
        $yoga = $this->buatPelanggan('Yoga Wicaksono', 'yoga-wicaksono');
        $lYoga = $this->buatLayanan($yoga, $silver, $this->waktuBulan(-1, 2));
        $tYoga0 = $this->buatTagihan($lYoga, -1);
        $tYogaSekarang = $this->buatTagihan($lYoga, 0);
        $this->bayarTunai($yoga, (float) $tYoga0->total_tagihan + 150000, [$tYoga0->id], $this->waktuBulan(-1, 25, 9, 0));
        $this->pakaiKredit($yoga, [$tYogaSekarang->id], $this->waktuBulan(0, 10, 10, 0));
        $this->bayarTunai($yoga, round((float) $tYogaSekarang->total_tagihan - 150000, 2), [$tYogaSekarang->id], $this->waktuBulan(0, 11, 13, 0));

        // Nova — deposit murni: bayar tunai di atas tagihan, sisa 200rb jadi kredit.
        $nova = $this->buatPelanggan('Nova Ramadhani', 'nova-ramadhani');
        $lNova = $this->buatLayanan($nova, $bronze, $this->waktuBulan(-2, 5));
        $tNova = $this->buatTagihan($lNova, 0);
        $this->bayarTunai($nova, (float) $tNova->total_tagihan + 200000, [$tNova->id], $this->waktuBulan(0, 6, 10, 0));

        // Bagus — tagihan ditimpa gagal + pending, lalu dibayar sebagian (sisa berdiri).
        $bagus = $this->buatPelanggan('Bagus Saputra', 'bagus-saputra');
        $lBagus = $this->buatLayanan($bagus, $gold, $this->waktuBulan(-1, 7));
        $tBagus0 = $this->buatTagihan($lBagus, -1);
        $this->bayarXendit($bagus, (float) $tBagus0->total_tagihan, [$tBagus0->id], $this->waktuBulan(-1, 14, 12, 45));
        $tBagus = $this->buatTagihan($lBagus, 0);
        $this->buatGagal($bagus, (float) $tBagus->total_tagihan, $this->waktuHariLalu(3, 8, 20));
        $this->buatPending($bagus, (float) $tBagus->total_tagihan, $this->waktuHariLalu(1, 19, 45));
        $this->bayarTunai($bagus, 100000, [$tBagus->id], $this->waktuHariLalu(1, 10, 15));

        // Fauzi — tagihan multi-bulan (3 bulan sekali bayar), Xendit melunasi.
        $fauzi = $this->buatPelanggan('Fauzi Rahman', 'fauzi-rahman');
        $lFauzi = $this->buatLayanan($fauzi, $gold, $this->waktuBulan(-5, 1));
        $tMulti = $this->buatTagihan($lFauzi, -4, 3);
        $this->bayarXendit($fauzi, (float) $tMulti->total_tagihan, [$tMulti->id], $this->waktuBulan(-1, 21, 10, 0));
        $tFauzi1 = $this->buatTagihan($lFauzi, -1);
        $this->bayarTunai($fauzi, (float) $tFauzi1->total_tagihan, [$tFauzi1->id], $this->waktuBulan(-1, 5, 9, 30));
        $this->buatTagihan($lFauzi, 0);

        // Citra — 3 tagihan dibayar GABUNGAN sekali (pembayaran gabungan terbaru).
        $citra = $this->buatPelanggan('Citra Ayu', 'citra-ayu');
        $lCitra = $this->buatLayanan($citra, $bronze, $this->waktuBulan(-2, 3));
        $tagihanCitra = [];
        for ($x = -2; $x <= 0; $x++) {
            $tagihanCitra[$x] = $this->buatTagihan($lCitra, $x);
        }
        $this->bayarTunai(
            $citra,
            (float) $tagihanCitra[-2]->total_tagihan + (float) $tagihanCitra[-1]->total_tagihan + (float) $tagihanCitra[0]->total_tagihan,
            [$tagihanCitra[-2]->id, $tagihanCitra[-1]->id, $tagihanCitra[0]->id],
            $this->waktuHariLalu(2, 11, 30),
        );

        // Hadi — sebelumnya gabungan Xendit, lalu dua bulan detil; bulan berjalan belum.
        $hadi = $this->buatPelanggan('Hadi Sentosa', 'hadi-sentosa');
        $lHadi = $this->buatLayanan($hadi, $silver, $this->waktuBulan(-5, 9));
        $tHadi = [];
        for ($x = -5; $x <= -3; $x++) {
            $tHadi[$x] = $this->buatTagihan($lHadi, $x);
        }
        $this->bayarXendit(
            $hadi,
            (float) $tHadi[-5]->total_tagihan + (float) $tHadi[-4]->total_tagihan + (float) $tHadi[-3]->total_tagihan,
            [$tHadi[-5]->id, $tHadi[-4]->id, $tHadi[-3]->id],
            $this->waktuBulan(-3, 20, 16, 30),
        );
        for ($x = -2; $x <= 0; $x++) {
            $t = $this->buatTagihan($lHadi, $x);
            if ($x < 0) {
                $this->bayarTunai($hadi, (float) $t->total_tagihan, [$t->id], $this->waktuBulan($x, 15, 9, 0));
            }
        }

        // Raka — riwayat 7 bulan lunas, layanan dinonaktifkan di akhir.
        $raka = $this->buatPelanggan('Raka Nugraha', 'raka-nugraha');
        $lRaka = $this->buatLayanan($raka, $bronze, $this->waktuBulan(-6, 11));
        for ($x = -6; $x <= 0; $x++) {
            $t = $this->buatTagihan($lRaka, $x);
            $this->bayarTunai($raka, (float) $t->total_tagihan, [$t->id], $this->waktuBulan($x, 12, 8, 0));
        }
        $lRaka->update(['status' => StatusLayananEnum::NONAKTIF]);

        // Amelia — paket promo: bulan pertama gratis (bebas_tagihan_bulan = 1).
        $amel = $this->buatPelanggan('Amelia Putri', 'amelia-putri');
        $lAmel = $this->buatLayanan($amel, $promoKeluarga, $this->waktuBulan(-7, 14), 1);
        for ($x = -6; $x <= 0; $x++) {
            $t = $this->buatTagihan($lAmel, $x);
            $this->bayarTunai($amel, (float) $t->total_tagihan, [$t->id], $this->waktuBulan($x, 10, 17, 0));
        }

        // Deni — tanggal_tagihan 5 (awal bulan), lunas Xendit.
        $deni = $this->buatPelanggan('Deni Hakim', 'deni-hakim', 5);
        $lDeni = $this->buatLayanan($deni, $silver, $this->waktuBulan(-6, 16));
        for ($x = -6; $x <= 0; $x++) {
            $t = $this->buatTagihan($lDeni, $x);
            $this->bayarXendit($deni, (float) $t->total_tagihan, [$t->id], $this->waktuBulan($x, 5, 15, 30));
        }

        // Putri — tanggal_tagihan 31 (snap akhir bulan), lunas tunai.
        $putri = $this->buatPelanggan('Putri Ayu', 'putri-ayu', 31);
        $lPutri = $this->buatLayanan($putri, $gold, $this->waktuBulan(-4, 2));
        for ($x = -4; $x <= 0; $x++) {
            $t = $this->buatTagihan($lPutri, $x);
            $this->bayarTunai($putri, (float) $t->total_tagihan, [$t->id], $this->waktuBulan($x, 27, 10, 45));
        }

        // Eko — bulan lalu & berjalan belum bayar (masih ringan).
        $eko = $this->buatPelanggan('Eko Prayitno', 'eko-prayitno');
        $lEko = $this->buatLayanan($eko, $silver, $this->waktuBulan(-1, 13));
        $this->buatTagihan($lEko, -1);
        $this->buatTagihan($lEko, 0);

        // Wulan — gabungan 2 tagihan di bulan berjalan.
        $wulan = $this->buatPelanggan('Wulan Sari', 'wulan-sari');
        $lWulan = $this->buatLayanan($wulan, $gold, $this->waktuBulan(-3, 6));
        $tWulan = [];
        for ($x = -3; $x <= 0; $x++) {
            $tWulan[$x] = $this->buatTagihan($lWulan, $x);
        }
        $this->bayarTunai($wulan, (float) $tWulan[-3]->total_tagihan, [$tWulan[-3]->id], $this->waktuBulan(-3, 20, 9, 0));
        $this->bayarTunai($wulan, (float) $tWulan[-2]->total_tagihan, [$tWulan[-2]->id], $this->waktuBulan(-2, 15, 9, 0));
        $this->bayarTunai(
            $wulan,
            (float) $tWulan[-1]->total_tagihan + (float) $tWulan[0]->total_tagihan,
            [$tWulan[-1]->id, $tWulan[0]->id],
            $this->waktuHariLalu(1, 16, 0),
        );

        // Nadia — 2 bulan lunas Xendit, bulan berjalan belum + pending.
        $nadia = $this->buatPelanggan('Nadia Rizki', 'nadia-rizki');
        $lNadia = $this->buatLayanan($nadia, $platinum, $this->waktuBulan(-2, 18));
        for ($x = -2; $x <= 0; $x++) {
            $t = $this->buatTagihan($lNadia, $x);
            if ($x < 0) {
                $this->bayarXendit($nadia, (float) $t->total_tagihan, [$t->id], $this->waktuBulan($x, 11, 14, 10));
            }
        }
        $this->buatPending($nadia, 600000, $this->waktuHariLalu(1, 8, 30));

        // Ari — pelanggan premium lunas 13 bulan disiplin.
        $ari = $this->buatPelanggan('Ari Wibowo', 'ari-wibowo');
        $lAri = $this->buatLayanan($ari, $diamond, $this->waktuBulan(-12, 9));
        for ($x = -12; $x <= 0; $x++) {
            $t = $this->buatTagihan($lAri, $x);
            $this->bayarTunai($ari, (float) $t->total_tagihan, [$t->id], $this->waktuBulan($x, 3, 8, 0));
        }

        // --- Skenario di bawah reseller utama (Rina Reseller) ---
        $rina = Admin::where('email', 'reseller@sicakra.com')->firstOrFail();

        $rohman = $this->buatPelanggan('Rohman Hidayat', 'rohman-hidayat', 20, $rina);
        $lRohman = $this->buatLayanan($rohman, $resellerNet, $this->waktuBulan(-8, 10));
        for ($x = -8; $x <= 0; $x++) {
            $t = $this->buatTagihan($lRohman, $x);
            $this->bayarTunai($rohman, (float) $t->total_tagihan, [$t->id], $this->waktuBulan($x, 20, 9, 0), $rina->nama_lengkap);
        }

        $yusuf = $this->buatPelanggan('Yusuf Ramli', 'yusuf-ramli', 20, $rina);
        $lYusuf = $this->buatLayanan($yusuf, $resellerNet, $this->waktuBulan(-3, 4));
        for ($x = -3; $x <= 0; $x++) {
            $this->buatTagihan($lYusuf, $x);
        }

        $hasyim = $this->buatPelanggan('Hasyim Asrori', 'hasyim-asrori', 20, $rina);
        $lHasyim = $this->buatLayanan($hasyim, $resellerNet, $this->waktuBulan(-2, 6));
        $tHasyim0 = $this->buatTagihan($lHasyim, -2);
        $tHasyim1 = $this->buatTagihan($lHasyim, -1);
        $this->buatTagihan($lHasyim, 0);
        $this->bayarTunai($hasyim, (float) $tHasyim0->total_tagihan + 200000, [$tHasyim0->id], $this->waktuBulan(-2, 22, 10, 0), $rina->nama_lengkap);
        $this->pakaiKredit($hasyim, [$tHasyim1->id], $this->waktuBulan(-1, 10, 9, 0));
        $this->bayarTunai($hasyim, round((float) $tHasyim1->total_tagihan - 200000, 2), [$tHasyim1->id], $this->waktuBulan(-1, 11, 10, 30), $rina->nama_lengkap);

        $farid = $this->buatPelanggan('Farid Maulana', 'farid-maulana', 20, $rina);
        $lFarid = $this->buatLayanan($farid, $silver, $this->waktuBulan(-2, 15));
        for ($x = -2; $x <= 0; $x++) {
            $t = $this->buatTagihan($lFarid, $x);
            if ($x < 0) {
                $this->bayarTunai($farid, (float) $t->total_tagihan, [$t->id], $this->waktuBulan($x, 18, 9, 0), $rina->nama_lengkap);
            }
        }
        $this->buatPending($farid, 250000, $this->waktuHariLalu(1, 10, 0));

        $ahmad = $this->buatPelanggan('Ahmad Zaenuri', 'ahmad-zaenuri', 20, $rina);
        $lAhmad = $this->buatLayanan($ahmad, $bronze, $this->waktuBulan(-6, 12));
        for ($x = -6; $x <= 0; $x++) {
            $t = $this->buatTagihan($lAhmad, $x);
            $this->bayarTunai($ahmad, (float) $t->total_tagihan, [$t->id], $this->waktuBulan($x, 8, 11, 0), $rina->nama_lengkap);
        }

        $bambang = $this->buatPelanggan('Bambang Sutrisno', 'bambang-sutrisno', 20, $rina);
        $lBambang = $this->buatLayanan($bambang, $gold, $this->waktuBulan(-5, 8));
        $tBambang = [];
        for ($x = -5; $x <= 0; $x++) {
            $tBambang[$x] = $this->buatTagihan($lBambang, $x);
        }
        for ($x = -5; $x <= -3; $x++) {
            $this->bayarTunai($bambang, (float) $tBambang[$x]->total_tagihan, [$tBambang[$x]->id], $this->waktuBulan($x, 12, 13, 0), $rina->nama_lengkap);
        }
        $this->bayarTunai(
            $bambang,
            (float) $tBambang[-2]->total_tagihan + (float) $tBambang[-1]->total_tagihan,
            [$tBambang[-2]->id, $tBambang[-1]->id],
            $this->waktuHariLalu(4, 11, 0),
            $rina->nama_lengkap,
        );

        $tuti = $this->buatPelanggan('Tuti Herlina', 'tuti-herlina', 20, $rina);
        $lTuti = $this->buatLayanan($tuti, $silver, $this->waktuBulan(-7, 3));
        for ($x = -7; $x <= 0; $x++) {
            $t = $this->buatTagihan($lTuti, $x);
            $this->bayarXendit($tuti, (float) $t->total_tagihan, [$t->id], $this->waktuBulan($x, 22, 18, 30));
        }

        $salim = $this->buatPelanggan('Salim Ahmad', 'salim-ahmad', 20, $rina);
        $lSalim = $this->buatLayanan($salim, $bronze, $this->waktuBulan(-3, 1));
        $tSalim = [];
        for ($x = -3; $x <= 0; $x++) {
            $tSalim[$x] = $this->buatTagihan($lSalim, $x);
        }
        for ($x = -1; $x <= 0; $x++) {
            $this->bayarTunai($salim, (float) $tSalim[$x]->total_tagihan, [$tSalim[$x]->id], $this->waktuBulan($x, 15, 9, 0), $rina->nama_lengkap);
        }
    }

    // ------------------------------------------------------------------
    // Reseller baru + volume
    // ------------------------------------------------------------------

    private function seedResellerBaru(): void
    {
        $kelompok = [
            [
                'email' => 'reseller-cirebon@sicakra.com',
                'nama' => 'Pantura Fiber Cirebon',
                'paket' => [['Pantura 20 Mbps', 20, 170000], ['Pantura 50 Mbps', 50, 320000]],
                'pelanggan' => [
                    ['nama' => 'Herni Suryani', 'bulan' => 7, 'pola' => 'cash'],
                    ['nama' => 'Dadang Koswara', 'bulan' => 6, 'pola' => 'xendit'],
                    ['nama' => 'Irfan Maulana', 'bulan' => 8, 'pola' => 'cash'],
                    ['nama' => 'Sulis Tri Astuti', 'bulan' => 6, 'pola' => 'tunggak'],
                    ['nama' => 'Ajat Sudrajat', 'bulan' => 7, 'pola' => 'cicil'],
                    ['nama' => 'Tina Marlina', 'bulan' => 7, 'pola' => 'over'],
                    ['nama' => 'Ujang Saepul', 'bulan' => 6, 'pola' => 'cash'],
                    ['nama' => 'Dede Kurnia', 'bulan' => 8, 'pola' => 'xendit'],
                ],
            ],
            [
                'email' => 'reseller-majalengka@sicakra.com',
                'nama' => 'Nusantara Net Majalengka',
                'paket' => [['Nusantara Home 15', 15, 130000], ['Nusantara Biz 40', 40, 300000]],
                'pelanggan' => [
                    ['nama' => 'Tuti Suhartini', 'bulan' => 7, 'pola' => 'xendit'],
                    ['nama' => 'Cecep Rukmana', 'bulan' => 8, 'pola' => 'cash'],
                    ['nama' => 'Neng Yanti', 'bulan' => 6, 'pola' => 'cicil'],
                    ['nama' => 'Asep Sunandar', 'bulan' => 7, 'pola' => 'tunggak'],
                    ['nama' => 'Imas Masitoh', 'bulan' => 6, 'pola' => 'cash'],
                    ['nama' => 'Endang Kurnaesih', 'bulan' => 8, 'pola' => 'over'],
                    ['nama' => 'Ridwan Kamil', 'bulan' => 7, 'pola' => 'cash'],
                    ['nama' => 'Yeti Suryati', 'bulan' => 6, 'pola' => 'xendit'],
                ],
            ],
            [
                'email' => 'reseller-banyuwangi@sicakra.com',
                'nama' => 'Blambangan WiFi Banyuwangi',
                'paket' => [['Blambangan Starter', 25, 190000], ['Blambangan Plus 75', 75, 380000]],
                'pelanggan' => [
                    ['nama' => 'Joko Santoso', 'bulan' => 7, 'pola' => 'cash'],
                    ['nama' => 'Siti Aminah', 'bulan' => 8, 'pola' => 'cash'],
                    ['nama' => 'Bambang Irawan', 'bulan' => 6, 'pola' => 'xendit'],
                    ['nama' => 'Lilis Nurlaila', 'bulan' => 7, 'pola' => 'over'],
                    ['nama' => 'Slamet Riyadi', 'bulan' => 6, 'pola' => 'cicil'],
                    ['nama' => 'Endah Puspita', 'bulan' => 8, 'pola' => 'cash'],
                    ['nama' => 'Purnomo Wibowo', 'bulan' => 7, 'pola' => 'tunggak'],
                    ['nama' => 'Nur Handayani', 'bulan' => 6, 'pola' => 'xendit'],
                ],
            ],
            [
                'email' => 'reseller-lombok@sicakra.com',
                'nama' => 'Sasak Net Lombok',
                'paket' => [['Sasak Keluarga 30', 30, 210000], ['Sasak Badan Usaha 60', 60, 350000]],
                'pelanggan' => [
                    ['nama' => 'Lalu Gede Arta', 'bulan' => 7, 'pola' => 'cash'],
                    ['nama' => 'Ni Made Wati', 'bulan' => 6, 'pola' => 'cash'],
                    ['nama' => 'I Wayan Sudiarta', 'bulan' => 8, 'pola' => 'xendit'],
                    ['nama' => 'Baiq Rina', 'bulan' => 7, 'pola' => 'over'],
                    ['nama' => 'H. Maulidin', 'bulan' => 6, 'pola' => 'tunggak'],
                    ['nama' => 'Sari Indah', 'bulan' => 7, 'pola' => 'cicil'],
                    ['nama' => 'Kholilurrahman', 'bulan' => 6, 'pola' => 'cash'],
                    ['nama' => 'Nurhayati', 'bulan' => 8, 'pola' => 'xendit'],
                ],
            ],
            [
                'email' => 'reseller-medan@sicakra.com',
                'nama' => 'Pinga Net Medan',
                'paket' => [['Pinga Rumah 25', 25, 180000], ['Pinga Office 100', 100, 520000]],
                'pelanggan' => [
                    ['nama' => 'Rajagukguk Bakti', 'bulan' => 7, 'pola' => 'cash'],
                    ['nama' => 'Sri Wahyu Ningsih', 'bulan' => 8, 'pola' => 'xendit'],
                    ['nama' => 'Daniel Siregar', 'bulan' => 6, 'pola' => 'cash'],
                    ['nama' => 'Rahma Dani', 'bulan' => 7, 'pola' => 'cicil'],
                    ['nama' => 'Mahmud Zaki', 'bulan' => 6, 'pola' => 'over'],
                    ['nama' => 'Hanna Priscilla', 'bulan' => 8, 'pola' => 'cash'],
                    ['nama' => 'Agustina Lumban', 'bulan' => 7, 'pola' => 'tunggak'],
                    ['nama' => 'Frans Silitonga', 'bulan' => 6, 'pola' => 'xendit'],
                ],
            ],
        ];

        foreach ($kelompok as $data) {
            $reseller = Admin::create([
                'nama_lengkap' => $data['nama'],
                'email' => $data['email'],
                'password' => 'password123',
                'peran' => PeranAdminEnum::RESELLER,
                'status_aktif' => true,
            ]);

            foreach ($data['paket'] as [$nama, $mbps, $harga]) {
                PaketInternet::create([
                    'nama_paket' => $nama,
                    'kecepatan_mbps' => $mbps,
                    'harga' => $harga,
                    'jumlah_perangkat' => 20,
                    'deskripsi' => 'Paket eksklusif dari reseller '.$data['nama'].'.',
                    'status_aktif' => true,
                    'promo_gratis_bulan' => 1,
                    'reseller_id' => $reseller->id,
                ]);
            }

            $paket1 = PaketInternet::where('nama_paket', $data['paket'][0][0])->firstOrFail();

            $daftar = array_map(fn ($p) => array_merge($p, ['paket' => $paket1]), $data['pelanggan']);
            $this->seedPasokan($daftar, $reseller, $reseller->nama_lengkap);
        }
    }

    // ------------------------------------------------------------------
    // Status keuangan berjalan (sedang cicil + deposit)
    // ------------------------------------------------------------------

    /**
     * Contoh status BERJALAN di admin utama & reseller utama:
     *  - "Sedang Cicil": tagihan periode berjalan dibayar sebagian.
     *  - Deposit: kelebihan bayar (bayar lebih) bersisa sebagai saldo kredit.
     */
    private function seedStatusBerjalan(
        PaketInternet $bronze,
        PaketInternet $silver,
        PaketInternet $resellerNet,
    ): void {
        $rina = Admin::where('email', 'reseller@sicakra.com')->firstOrFail();

        // Admin utama: sedang cicil — tagihan bulan berjalan dibayar 50%.
        if (! Pelanggan::where('username', 'galih-putra')->exists()) {
            $galih = $this->buatPelanggan('Galih Putra', 'galih-putra');
            $lGalih = $this->buatLayanan($galih, $bronze, $this->waktuBulan(-1, 2));
            $tGalihSebelum = $this->buatTagihan($lGalih, -1);
            $this->bayarTunai($galih, (float) $tGalihSebelum->total_tagihan, [$tGalihSebelum->id], $this->waktuBulan(-1, 18, 9, 0));
            $tGalihSekarang = $this->buatTagihan($lGalih, 0);
            $this->bayarTunai($galih, round((float) $tGalihSekarang->total_tagihan / 2, 2), [$tGalihSekarang->id], $this->waktuHariLalu(0, 9, 30));
        }

        // Admin utama: deposit — bayar lebih 300rb, saldo tersisa.
        if (! Pelanggan::where('username', 'ratih-purnama')->exists()) {
            $ratih = $this->buatPelanggan('Ratih Purnama', 'ratih-purnama');
            $lRatih = $this->buatLayanan($ratih, $silver, $this->waktuBulan(-1, 4));
            $tRatih = $this->buatTagihan($lRatih, 0);
            $this->bayarTunai($ratih, (float) $tRatih->total_tagihan + 300000, [$tRatih->id], $this->waktuHariLalu(1, 10, 0));
        }

        // Reseller utama: sedang cicil.
        if (! Pelanggan::where('username', 'wawan-setiawan')->exists()) {
            $wawan = $this->buatPelanggan('Wawan Setiawan', 'wawan-setiawan', 20, $rina);
            $lWawan = $this->buatLayanan($wawan, $resellerNet, $this->waktuBulan(-1, 5));
            $tWawanSebelum = $this->buatTagihan($lWawan, -1);
            $this->bayarTunai($wawan, (float) $tWawanSebelum->total_tagihan, [$tWawanSebelum->id], $this->waktuBulan(-1, 21, 9, 0), $rina->nama_lengkap);
            $tWawanSekarang = $this->buatTagihan($lWawan, 0);
            $this->bayarTunai($wawan, round((float) $tWawanSekarang->total_tagihan / 2, 2), [$tWawanSekarang->id], $this->waktuHariLalu(0, 8, 45), $rina->nama_lengkap);
        }

        // Reseller utama: deposit — bayar lebih 250rb, saldo tersisa.
        if (! Pelanggan::where('username', 'halimah-sadiyah')->exists()) {
            $halimah = $this->buatPelanggan('Halimah Sadiyah', 'halimah-sadiyah', 20, $rina);
            $lHalimah = $this->buatLayanan($halimah, $resellerNet, $this->waktuBulan(-1, 6));
            $tHalimah = $this->buatTagihan($lHalimah, 0);
            $this->bayarTunai($halimah, (float) $tHalimah->total_tagihan + 250000, [$tHalimah->id], $this->waktuHariLalu(0, 14, 15), $rina->nama_lengkap);
        }
    }

    // ------------------------------------------------------------------
    // Draft tagihan reseller (halaman Terbitkan Tagihan)
    // ------------------------------------------------------------------

    /**
     * Draft tagihan bulan depan untuk semua pelanggan reseller (utama + baru)
     * agar halaman "Terbitkan Tagihan" di portal reseller punya banyak draft.
     */
    private function seedDraftReseller(): void
    {
        foreach ([
            'rohman-hidayat', 'yusuf-ramli', 'hasyim-asrori', 'farid-maulana',
            'ahmad-zaenuri', 'bambang-sutrisno', 'tuti-herlina', 'salim-ahmad',
        ] as $username) {
            $pelanggan = Pelanggan::where('username', $username)->first();
            $layanan = $pelanggan?->layananInternet()->first();
            if ($layanan) {
                $this->buatDraft($layanan, $this->periode(1));
            }
        }

        $resellerLain = Admin::where('peran', PeranAdminEnum::RESELLER)
            ->where('email', '!=', 'reseller@sicakra.com')
            ->pluck('id');

        foreach (Pelanggan::whereIn('reseller_id', $resellerLain)->with('layananInternet')->get() as $pelanggan) {
            $layanan = $pelanggan->layananInternet->first();
            if ($layanan) {
                $this->buatDraft($layanan, $this->periode(1));
            }
        }
    }

    // ------------------------------------------------------------------
    // Pasokan volume (rumah tangga / reseller)
    // ------------------------------------------------------------------

    private function seedPasokan(array $daftar, ?Admin $reseller, string $dibayarOleh): void
    {
        $i = 0;
        foreach ($daftar as $data) {
            $i++;
            $username = Str::slug($data['nama']) . '-' . ($reseller ? 'r' . $reseller->id : 'k' . $i);
            $pelanggan = $this->buatPelanggan($data['nama'], $username, 20, $reseller);
            $layanan = $this->buatLayanan($pelanggan, $data['paket'], $this->waktuBulan(-($data['bulan'] - 1), $i % 9 + 2));

            $tagihan = [];
            for ($x = -($data['bulan'] - 1); $x <= 0; $x++) {
                $tagihan[$x] = $this->buatTagihan($layanan, $x);
            }

            $harga = (float) $data['paket']->harga;

            if ($data['pola'] === 'cash' || $data['pola'] === 'xendit') {
                for ($x = -($data['bulan'] - 1); $x <= 0; $x++) {
                    if ($x === 0 || ! isset($tagihan[$x])) {
                        continue;
                    }
                    $hari = 8 + (($i * 5 + ($x + $data['bulan'])) % 14);
                    $jam = 9 + (($i + $x) % 11);
                    if ($data['pola'] === 'cash') {
                        $this->bayarTunai($pelanggan, (float) $tagihan[$x]->total_tagihan, [$tagihan[$x]->id], $this->waktuBulan($x, $hari, $jam, ($i + $x) % 60), $dibayarOleh);
                    } else {
                        $this->bayarXendit($pelanggan, (float) $tagihan[$x]->total_tagihan, [$tagihan[$x]->id], $this->waktuBulan($x, $hari, $jam, ($i + $x) % 60));
                    }
                }
            } elseif ($data['pola'] === 'cicil') {
                for ($x = -($data['bulan'] - 1); $x <= 0; $x++) {
                    if ($x === 0 || ! isset($tagihan[$x])) {
                        continue;
                    }
                    $setengah = round((float) $tagihan[$x]->total_tagihan / 2, 2);
                    $this->bayarTunai($pelanggan, $setengah, [$tagihan[$x]->id], $this->waktuBulan($x, 4 + ($i % 10), 10, $i % 60), $dibayarOleh);
                }
            } elseif ($data['pola'] === 'over') {
                for ($x = -($data['bulan'] - 1); $x <= 0; $x++) {
                    if ($x === 0 || ! isset($tagihan[$x])) {
                        continue;
                    }
                    if ($x === -1) {
                        // Satu bulan terakhir: saldo kredit dipakai separuh, sisanya tunai.
                        $separuh = round((float) $tagihan[$x]->total_tagihan / 2, 2);
                        $this->pakaiKredit($pelanggan, [$tagihan[$x]->id], $this->waktuBulan($x, 10, ($i + $x) % 9 + 9, ($i + $x) % 60));
                        $this->bayarTunai($pelanggan, round((float) $tagihan[$x]->total_tagihan - $separuh, 2), [$tagihan[$x]->id], $this->waktuBulan($x, 12, ($i + $x) % 9 + 9, ($i + $x) % 60), $dibayarOleh);
                        continue;
                    }
                    $kelebihan = (int) round($harga * 0.4);
                    $this->bayarTunai($pelanggan, (float) $tagihan[$x]->total_tagihan + $kelebihan, [$tagihan[$x]->id], $this->waktuBulan($x, 16 + ($i % 8), 14, ($i + $x) % 60), $dibayarOleh);
                }
            }
            // 'tunggak': tidak ada pembayaran — seluruh tagihan menggantung.
        }
    }

    // ------------------------------------------------------------------
    // Penutup: draft bulan depan + notifikasi badge
    // ------------------------------------------------------------------

    private function seedPenutup(PaketInternet $silver, PaketInternet $gold): void
    {
        $tujuan = [
            'andri-prasetyo' => 0,
            'citra-ayu' => 0,
            'rohman-hidayat' => 0,
            'maya-astuti' => 1,
            'intan-permata' => 0,
        ];

        foreach ($tujuan as $username => $urutanLayanan) {
            $pelanggan = Pelanggan::where('username', $username)->first();
            $layanan = $pelanggan?->layananInternet()->orderBy('id')->get()[$urutanLayanan] ?? null;
            if ($layanan) {
                $berikut = $this->periode(1);
                $this->buatDraft($layanan, $berikut);
            }
        }

        // Notifikasi pembayaran terbaru untuk badge admin keuangan.
        foreach (Pembayaran::where('status', StatusTransaksiEnum::BERHASIL)->orderByDesc('id')->limit(2)->get() as $pembayaran) {
            \App\Events\PembayaranBerhasil::dispatch($pembayaran->loadMissing('alokasiTagihan.tagihan.layananInternet.pelanggan'));
        }
    }

    // ------------------------------------------------------------------
    // Helper master data
    // ------------------------------------------------------------------

    private function paket(string $nama): PaketInternet
    {
        return PaketInternet::where('nama_paket', $nama)->firstOrFail();
    }

    private function buatPelanggan(string $nama, string $username, int $tanggalTagihan = 20, ?Admin $reseller = null): Pelanggan
    {
        $this->nomorHp++;
        $this->nik++;

        return Pelanggan::create([
            'nomor_pelanggan' => $this->generator->generate(Pelanggan::class, 'nomor_pelanggan', 'PLG', true),
            'username' => $username,
            'nama_lengkap' => $nama,
            'nik' => (string) $this->nik,
            'nomor_hp' => '0812'.$this->nomorHp,
            'email' => $username.'@sicakra-demo.com',
            'password' => 'password123',
            'password_sudah_dibuat' => true,
            'tanggal_tagihan' => $tanggalTagihan,
            'foto_ktp' => 'ktp/dummy.jpg',
            'reseller_id' => $reseller?->id,
        ]);
    }

    private function buatLayanan(Pelanggan $pelanggan, PaketInternet $paket, Carbon $tanggalAktif, int $bebasBulan = 0): LayananInternet
    {
        return LayananInternet::create([
            'nomor_layanan' => $this->generator->generate(LayananInternet::class, 'nomor_layanan', 'LYN'),
            'permohonan_layanan_id' => null,
            'pelanggan_id' => $pelanggan->id,
            'paket_internet_id' => $paket->id,
            'tipe_paket' => TipePaketEnum::REGULER,
            'nama_paket_custom' => null,
            'alamat_pemasangan' => $this->alamat(),
            'latitude' => -7.79 + ($pelanggan->id % 7) / 1000,
            'longitude' => 110.36 + ($pelanggan->id % 5) / 1000,
            'status' => StatusLayananEnum::AKTIF,
            'tanggal_aktif' => $tanggalAktif->toDateString(),
            'bebas_tagihan_bulan' => $bebasBulan,
            'tanggal_mulai_penagihan' => $this->siklus->snapKeBulan(
                Carbon::today()->addMonthNoOverflow(1),
                (int) $pelanggan->tanggal_tagihan,
            )->toDateString(),
        ]);
    }

    private function alamat(): string
    {
        $lingkungan = ['Merapi', 'Prambanan', 'Parangtritis', 'Kaliurang', 'Godean', 'Monjali', 'Wonosari', 'Magelang'];
        $bantuk = ['RT 02 RW 05', 'RT 05 RW 03', 'RT 01 RW 02', 'RT 04 RW 07', 'RT 03 RW 01', 'RT 06 RW 04'];

        return 'Jalan '.$lingkungan[array_rand($lingkungan)].', '
            .$bantuk[array_rand($bantuk)].', '
            .'Sleman, Daerah Istimewa Yogyakarta, Indonesia';
    }

    // ------------------------------------------------------------------
    // Helper keuangan (mengikuti pola DemoSeeder)
    // ------------------------------------------------------------------

    /** [bulan, tahun] bulan yang digeser sejumlah bulan dari hari ini. */
    private function periode(int $offset): array
    {
        $tanggal = Carbon::today()->addMonthsNoOverflow($offset);

        return [$tanggal->month, $tanggal->year];
    }

    private function buatTagihan(LayananInternet $layanan, int $offsetBulan, int $jumlahBulan = 1): Tagihan
    {
        [$bulan, $tahun] = $this->periode($offsetBulan);

        $tagihan = $this->generate->generateUntukLayanan($layanan, $bulan, $tahun, $jumlahBulan);
        if (! $tagihan) {
            throw new RuntimeException("Gagal generate tagihan {$bulan}/{$tahun} untuk layanan #{$layanan->id}.");
        }

        return $tagihan;
    }

    private function buatDraft(LayananInternet $layanan, int|array $offsetBulan): void
    {
        [$bulan, $tahun] = is_array($offsetBulan)
            ? $offsetBulan
            : $this->periode($offsetBulan);

        $this->generate->generateDraftUntukLayanan($layanan, $bulan, $tahun);
    }

    private function bayarTunai(Pelanggan $pelanggan, float $jumlah, array $tagihanIds, Carbon $waktu, ?string $dibayarOleh = null): Pembayaran
    {
        $pembayaran = $this->allocation->buatPembayaranTunai($pelanggan, $jumlah, [
            'metode_pembayaran' => 'tunai',
            'dibayar_oleh' => $dibayarOleh ?? $this->keuangan->nama_lengkap,
            'tagihan_terpilih' => array_map('intval', $tagihanIds),
        ]);

        $this->tundaWaktuPembayaran($pembayaran, $waktu);

        return $pembayaran;
    }

    private function bayarXendit(Pelanggan $pelanggan, float $jumlah, array $tagihanIds, Carbon $waktu): Pembayaran
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

        $this->allocation->selesaikanPembayaran($pembayaran->refresh());
        $this->tundaWaktuPembayaran($pembayaran->refresh(), $waktu);

        return $pembayaran->refresh();
    }

    private function buatPending(Pelanggan $pelanggan, float $jumlah, Carbon $waktu): void
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
    }

    private function buatGagal(Pelanggan $pelanggan, float $jumlah, Carbon $waktu): void
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
    }

    private function pakaiKredit(Pelanggan $pelanggan, array $tagihanIds, Carbon $waktu): void
    {
        $this->allocation->gunakanSaldoKredit($pelanggan, array_map('intval', $tagihanIds));

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

    private function waktuBulan(int $offsetBulan, int $hari, int $jam = 0, int $menit = 0): Carbon
    {
        $waktu = Carbon::today()->addMonthsNoOverflow($offsetBulan);

        return $waktu->setDay(min($hari, $waktu->daysInMonth))->setTime($jam, $menit, 0);
    }

    private function waktuHariLalu(int $hari, int $jam, int $menit): Carbon
    {
        return Carbon::now()->subDays($hari)->setTime($jam, $menit, 0);
    }
}