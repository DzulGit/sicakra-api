<?php

namespace Database\Seeders;

use App\Enums\HasilKerjaEnum;
use App\Enums\JenisPermohonanEnum;
use App\Enums\PeranAdminEnum;
use App\Enums\StatusLaporanEnum;
use App\Enums\StatusLayananEnum;
use App\Enums\StatusPembayaranEnum;
use App\Enums\StatusPerangkatEnum;
use App\Enums\StatusPermohonanEnum;
use App\Enums\StatusTransaksiEnum;
use App\Enums\TipePaketEnum;
use App\Models\Admin;
use App\Models\JadwalKerja;
use App\Models\LaporanKendala;
use App\Models\LayananInternet;
use App\Models\PaketInternet;
use App\Models\Pelanggan;
use App\Models\Pembayaran;
use App\Models\PembayaranTagihan;
use App\Models\Perangkat;
use App\Models\PermohonanLayanan;
use App\Models\MutasiSaldoKredit;
use App\Models\RiwayatStatusPermohonan;
use App\Models\Tagihan;
use App\Models\TimTeknisi;
use App\Notifications\LaporanKendalaBaruNotification;
use App\Notifications\PembayaranTagihanNotification;
use App\Notifications\PendaftarBaruNotification;
use App\Services\GeneratorNomorService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * DemoSeeder — data presentasi ringkas (11 pelanggan) yang mencakup hampir
 * semua state bisnis Sicakra. Fokus skenario:
 *  1. Lunas via tunai
 *  2. Lunas via Xendit (multi-bulan)
 *  3. Belum bayar (tunggakan sederhana)
 *  4. Cicilan parsial (sisa tagihan > 0)
 *  5. Pembayaran gabungan (satu bayar, dua tagihan)
 *  6. Deposit (kelebihan bayar -> saldo kredit)
 *  7. Saldo kredit digunakan utk melunasi tagihan
 *  8. Multi-layanan (reguler + custom)
 *  9-11. Milik reseller: lunas, belum bayar, & pelanggan baru (tanpa tagihan)
 *
 * Selain itu: permohonan pending (operasional), tiket kendala, jadwal kerja,
 * serta notifikasi unread untuk badge merah.
 */
class DemoSeeder extends Seeder
{
    private const DEMO_ADMIN_NAME = 'Admin Utama Demo';

    private const ALAMAT = [
        'Jalan Parangtritis, Ngestiharjo, Kasihan, Bantul, Daerah Istimewa Yogyakarta, 55182, Indonesia',
        'Jalan Kaliurang, Caturtunggal, Depok, Sleman, Daerah Istimewa Yogyakarta, 55281, Indonesia',
        'Jalan Monjali, Sinduadi, Mlati, Sleman, Daerah Istimewa Yogyakarta, 55284, Indonesia',
        'Jalan Wonosari, Potorono, Banguntapan, Bantul, Daerah Istimewa Yogyakarta, 55182, Indonesia',
        'Jalan Godean, Trihanggo, Gamping, Sleman, Daerah Istimewa Yogyakarta, 55291, Indonesia',
        'Jalan Magelang, Sendangadi, Mlati, Sleman, Daerah Istimewa Yogyakarta, 55285, Indonesia',
    ];

    private GeneratorNomorService $generator;

    private Admin $adminUtama;

    private Admin $operasional;

    private Admin $keuangan;

    private Admin $reseller;

    private array $teknisi = [];

    private array $tim = [];

    public function run(): void
    {
        if (Admin::where('nama_lengkap', self::DEMO_ADMIN_NAME)->exists()) {
            $this->command->warn('Data demo sudah pernah di-seed, dilewati.');

            return;
        }

        $this->generator = new GeneratorNomorService;

        $this->command->info('Phase 1 — Admin & Tim Teknisi');
        $this->seedAdminDanTim();

        $this->command->info('Phase 2 — Paket Internet (master)');
        $this->call(PaketInternetSeeder::class);

        $this->command->info('Phase 3 — Pelanggan, Layanan, Tagihan & Pembayaran (11 skenario)');
        $this->seedPelanggan();

        $this->command->info('Phase 4 — Permohonan tambahan & Laporan Kendala');
        $this->seedPermohonanTambahan();
        $this->seedLaporanKendala();

        $this->command->info('Phase 5 — Notifikasi unread ke Admin Utama');
        $this->seedNotifikasi();

        $this->command->info('DemoSeeder selesai. Pelanggan dapat login dengan username & password "password123".');
    }

    // ------------------------------------------------------------------
    // Phase 1 — Admin + Tim
    // ------------------------------------------------------------------
    private function seedAdminDanTim(): void
    {
        $this->adminUtama = Admin::updateOrCreate(['email' => 'admin@sicakra.com'], [
            'nama_lengkap' => self::DEMO_ADMIN_NAME,
            'password' => 'Admins1cakra',
            'peran' => PeranAdminEnum::SUPER_ADMIN,
            'status_aktif' => true,
        ]);

        $this->operasional = Admin::updateOrCreate(['email' => 'operasional@sicakra.com'], [
            'nama_lengkap' => 'Anwara Operasional',
            'password' => 'password123',
            'peran' => PeranAdminEnum::OPERASIONAL,
            'status_aktif' => true,
        ]);

        $this->keuangan = Admin::updateOrCreate(['email' => 'keuangan@sicakra.com'], [
            'nama_lengkap' => 'Kirana Keuangan',
            'password' => 'password123',
            'peran' => PeranAdminEnum::KEUANGAN,
            'status_aktif' => true,
        ]);

        $this->reseller = Admin::updateOrCreate(['email' => 'reseller@sicakra.com'], [
            'nama_lengkap' => 'Rina Reseller',
            'password' => 'password123',
            'peran' => PeranAdminEnum::RESELLER,
            'status_aktif' => true,
        ]);

        $namaTeknisi = ['Taufik Teknisi', 'Rizky Teknisi', 'Ahmad Teknisi', 'Gilang Teknisi'];
        foreach ($namaTeknisi as $i => $nama) {
            $this->teknisi[] = Admin::updateOrCreate(['email' => 'teknisi'.($i + 1).'@sicakra.com'], [
                'nama_lengkap' => $nama,
                'password' => 'password123',
                'peran' => PeranAdminEnum::TEKNISI,
                'status_aktif' => true,
            ]);
        }

        $susunanTim = [
            ['nama_tim' => 'Tim Sakura', 'anggota' => [$this->teknisi[0], $this->teknisi[1]]],
            ['nama_tim' => 'Tim Meranti', 'anggota' => [$this->teknisi[2], $this->teknisi[3]]],
        ];
        foreach ($susunanTim as $data) {
            $tim = TimTeknisi::firstOrCreate(['nama_tim' => $data['nama_tim']], ['status_aktif' => true]);
            $tim->anggota()->sync(array_map(fn ($t) => $t->id, $data['anggota']));
            $this->tim[] = $tim;
        }
    }

    // ------------------------------------------------------------------
    // Phase 3 — Pelanggan & skenario billing
    // ------------------------------------------------------------------
    private function seedPelanggan(): void
    {
        $silver = PaketInternet::where('nama_paket', 'Paket Silver')->first();
        $gold = PaketInternet::where('nama_paket', 'Paket Gold')->first();
        $bronze = PaketInternet::where('nama_paket', 'Paket Bronze')->first();
        $platinum = PaketInternet::where('nama_paket', 'Paket Platinum')->first();

        // 1. Lunas via tunai.
        $budi = $this->buatPelanggan('Budi Santoso', 'budisantoso', '081200000001', '340101010100001', null);
        $layananBudi = $this->buatLayanan($budi, $silver, Carbon::today()->subMonths(4));
        $tagihanBudi = $this->buatTagihan($layananBudi, 1);
        $this->tunai($budi, $tagihanBudi, $tagihanBudi->total_tagihan, Carbon::today()->subDay());

        // 2. Lunas via Xendit, tagihan multi-bulan (2 bulan).
        $siti = $this->buatPelanggan('Siti Nurhaliza', 'siti', '081200000002', '340202020200002', null);
        $layananSiti = $this->buatLayanan($siti, $gold, Carbon::today()->subMonths(6));
        $tagihanSiti = $this->buatTagihan($layananSiti, 2);
        $this->xendit($siti, [$tagihanSiti], $tagihanSiti->total_tagihan, Carbon::today()->subDay());

        // 3. Tunggakan sederhana (belum bayar).
        $agus = $this->buatPelanggan('Agus Wibowo', 'agus', '081200000003', '340303030300003', null);
        $layananAgus = $this->buatLayanan($agus, $silver, Carbon::today()->subMonths(3));
        $this->buatTagihan($layananAgus, 1);

        // 4. Cicilan parsial (sisa tagihan > 0).
        $dewi = $this->buatPelanggan('Dewi Lestari', 'dewi', '081200000004', '340404040400004', null);
        $layananDewi = $this->buatLayanan($dewi, $gold, Carbon::today()->subMonths(5));
        $tagihanDewi = $this->buatTagihan($layananDewi, 1);
        $this->tunai($dewi, $tagihanDewi, round((float) $tagihanDewi->total_tagihan / 2, 2), Carbon::today()->subDays(2));

        // 5. Pembayaran gabungan: 2 tagihan dibayar sekali.
        $wahyu = $this->buatPelanggan('Wahyu Nugroho', 'wahyu', '081200000005', '340505050500005', null);
        $layananWahyu = $this->buatLayanan($wahyu, $platinum, Carbon::today()->subMonths(6));
        $tagihan1 = $this->buatTagihan($layananWahyu, 2, Carbon::today()->subMonths(2));
        $tagihan2 = $this->buatTagihan($layananWahyu, 1);
        $this->xendit(
            $wahyu,
            [$tagihan1, $tagihan2],
            round((float) $tagihan1->total_tagihan + (float) $tagihan2->total_tagihan, 2),
            Carbon::today()->subDay(),
        );

        // 6. Deposit: kelebihan bayar -> saldo kredit 50k.
        $sri = $this->buatPelanggan('Sri Rahayu', 'sri', '081200000006', '340606060600006', null);
        $layananSri = $this->buatLayanan($sri, $bronze, Carbon::today()->subMonths(2));
        $tagihanSri = $this->buatTagihan($layananSri, 1);
        $this->tunai($sri, $tagihanSri, (float) $tagihanSri->total_tagihan + 50000, Carbon::today()->subDays(3));

        // 7. Saldo kredit dipakai melunasi tagihan (sisa saldo 50k).
        $joko = $this->buatPelanggan('Joko Susilo', 'joko', '081200000007', '340707070700007', null);
        $layananJoko = $this->buatLayanan($joko, $silver, Carbon::today()->subMonths(4));
        $this->deposit($joko, 300000, Carbon::today()->subMonths(2));
        $tagihanJoko = $this->buatTagihan($layananJoko, 1, Carbon::today()->subMonths(1));
        $this->pakaiSaldoKredit($joko, $tagihanJoko, 250000);

        // 8. Multi-layanan: reguler (lunas) + custom (belum bayar).
        $rina = $this->buatPelanggan('Rina Marlina', 'rina', '081200000008', '340808080800008', null);
        $layananRina1 = $this->buatLayanan($rina, $silver, Carbon::today()->subMonths(10));
        $tagihanRina1 = $this->buatTagihan($layananRina1, 1);
        $this->tunai($rina, $tagihanRina1, $tagihanRina1->total_tagihan, Carbon::today()->subDays(4));
        $layananRina2 = $this->buatLayananCustom($rina, 'Paket Kantor 50', 50, 400000, Carbon::today()->subMonths(6));
        $this->buatTagihan($layananRina2, 1);

        // 9-11. Milik reseller.
        $putra = $this->buatPelanggan('Putra Madani', 'putra', '081200000009', '340909090900009', $this->reseller);
        $layananPutra = $this->buatLayanan($putra, $silver, Carbon::today()->subMonths(3));
        $tagihanPutra = $this->buatTagihan($layananPutra, 1);
        $this->tunai($putra, $tagihanPutra, (float) $tagihanPutra->total_tagihan + 50000, Carbon::today()->subDay());

        $ilham = $this->buatPelanggan('Ilham Nusantara', 'ilham', '081200000010', '341010101000010', $this->reseller);
        $layananIlham = $this->buatLayanan($ilham, $gold, Carbon::today()->subMonths(2));
        $this->buatTagihan($layananIlham, 1);

        // Pelanggan baru reseller: layanan aktif, belum ada tagihan.
        $sulton = $this->buatPelanggan('Sulton Baru', 'sulton', '081200000011', '341111111100011', $this->reseller);
        $this->buatLayanan($sulton, $silver, Carbon::today()->subMonths(1));
    }

    private function buatPelanggan(string $nama, string $username, string $nomorHp, string $nik, ?Admin $reseller = null): Pelanggan
    {
        return Pelanggan::create([
            'nomor_pelanggan' => $this->generator->generate(Pelanggan::class, 'nomor_pelanggan', 'PLG', true),
            'username' => $username,
            'nama_lengkap' => $nama,
            'nik' => $nik,
            'nomor_hp' => $nomorHp,
            'email' => $username.'@sicakra-demo.com',
            'password' => 'password123',
            'password_sudah_dibuat' => true,
            'tanggal_tagihan' => 20,
            'foto_ktp' => 'ktp/dummy.jpg',
            'foto_selfie_ktp' => 'selfie-ktp/dummy.jpg',
            'reseller_id' => $reseller?->id,
        ]);
    }

    private function buatPermohonanDikonversi(Pelanggan $pelanggan, ?PaketInternet $paket): PermohonanLayanan
    {
        $alamat = self::ALAMAT[array_rand(self::ALAMAT)];

        $permohonan = PermohonanLayanan::create([
            'nomor_permohonan' => $this->generator->generate(PermohonanLayanan::class, 'nomor_permohonan', 'PMH'),
            'pelanggan_id' => $pelanggan->id,
            'jenis_permohonan' => JenisPermohonanEnum::PEMASANGAN_BARU,
            'paket_internet_id' => $paket?->id,
            'tipe_paket' => TipePaketEnum::REGULER,
            'alamat_pemasangan' => $alamat,
            'detail_alamat' => 'Pagar hitam depan warung Madura',
            'latitude' => -7.79,
            'longitude' => 110.36,
            'status' => StatusPermohonanEnum::DIKONVERSI,
            'diproses_oleh' => $this->operasional->id,
        ]);

        foreach ([
            [null, StatusPermohonanEnum::MENUNGGU_VERIFIKASI, 'Permohonan diajukan.'],
            [StatusPermohonanEnum::MENUNGGU_VERIFIKASI, StatusPermohonanEnum::DITERIMA, 'Data verifikasi sesuai.'],
            [StatusPermohonanEnum::DITERIMA, StatusPermohonanEnum::DIJADWALKAN, 'Pekerjaan dijadwalkan.'],
            [StatusPermohonanEnum::DIJADWALKAN, StatusPermohonanEnum::DIKONVERSI, 'Pemasangan selesai, layanan aktif.'],
        ] as [$sebelum, $sesudah, $catatan]) {
            RiwayatStatusPermohonan::create([
                'permohonan_layanan_id' => $permohonan->id,
                'status_sebelumnya' => $sebelum?->value,
                'status_sesudahnya' => $sesudah->value,
                'diubah_oleh' => $this->operasional->id,
                'catatan' => $catatan,
            ]);
        }

        return $permohonan;
    }

    private function buatLayanan(
        Pelanggan $pelanggan,
        ?PaketInternet $paket,
        Carbon $tanggalAktif,
    ): LayananInternet {
        $permohonan = $this->buatPermohonanDikonversi($pelanggan, $paket);

        $layanan = LayananInternet::create([
            'nomor_layanan' => $this->generator->generate(LayananInternet::class, 'nomor_layanan', 'LYN'),
            'permohonan_layanan_id' => $permohonan->id,
            'pelanggan_id' => $pelanggan->id,
            'paket_internet_id' => $paket?->id,
            'tipe_paket' => TipePaketEnum::REGULER,
            'alamat_pemasangan' => $permohonan->alamat_pemasangan,
            'detail_alamat' => $permohonan->detail_alamat,
            'latitude' => $permohonan->latitude,
            'longitude' => $permohonan->longitude,
            'status' => StatusLayananEnum::AKTIF,
            'tanggal_aktif' => $tanggalAktif->toDateString(),
            'bebas_tagihan_bulan' => 0,
            'tanggal_mulai_penagihan' => $this->snapKeBulan(
                Carbon::today()->addMonthNoOverflow(1),
                (int) $pelanggan->tanggal_tagihan
            ),
        ]);

        $this->buatPerangkat($layanan);

        // Backdate konversi & jadwal kerja ke tanggal aktif.
        RiwayatStatusPermohonan::where('permohonan_layanan_id', $permohonan->id)
            ->where('status_sesudahnya', StatusPermohonanEnum::DIKONVERSI->value)
            ->update(['created_at' => $tanggalAktif->toDateString().' 10:00:00']);

        return $layanan;
    }

    private function buatLayananCustom(Pelanggan $pelanggan, string $nama, int $mbps, int $harga, Carbon $tanggalAktif): LayananInternet
    {
        $alamat = self::ALAMAT[array_rand(self::ALAMAT)];

        $permohonan = PermohonanLayanan::create([
            'nomor_permohonan' => $this->generator->generate(PermohonanLayanan::class, 'nomor_permohonan', 'PMH'),
            'pelanggan_id' => $pelanggan->id,
            'jenis_permohonan' => JenisPermohonanEnum::TAMBAH_PAKET,
            'tipe_paket' => TipePaketEnum::CUSTOM,
            'nama_paket_custom' => $nama,
            'kecepatan_custom_mbps' => $mbps,
            'harga_custom' => $harga,
            'alamat_pemasangan' => $alamat,
            'detail_alamat' => 'Depan minimarket, seberang apotek',
            'latitude' => -7.79,
            'longitude' => 110.36,
            'status' => StatusPermohonanEnum::DIKONVERSI,
            'diproses_oleh' => $this->operasional->id,
        ]);

        return LayananInternet::create([
            'nomor_layanan' => $this->generator->generate(LayananInternet::class, 'nomor_layanan', 'LYN'),
            'permohonan_layanan_id' => $permohonan->id,
            'pelanggan_id' => $pelanggan->id,
            'paket_internet_id' => null,
            'tipe_paket' => TipePaketEnum::CUSTOM,
            'nama_paket_custom' => $nama,
            'kecepatan_custom_mbps' => $mbps,
            'harga_custom' => $harga,
            'alamat_pemasangan' => $permohonan->alamat_pemasangan,
            'detail_alamat' => $permohonan->detail_alamat,
            'latitude' => $permohonan->latitude,
            'longitude' => $permohonan->longitude,
            'status' => StatusLayananEnum::AKTIF,
            'tanggal_aktif' => $tanggalAktif->toDateString(),
            'bebas_tagihan_bulan' => 0,
            'tanggal_mulai_penagihan' => $this->snapKeBulan(
                Carbon::today()->addMonthNoOverflow(1),
                (int) $pelanggan->tanggal_tagihan
            ),
        ]);
    }

    private function buatPerangkat(LayananInternet $layanan): void
    {
        foreach (['ONT', 'Router'] as $tipe) {
            Perangkat::create([
                'layanan_internet_id' => $layanan->id,
                'serial_number' => 'SIC-'.$layanan->id.'-'.mt_rand(100000, 999999),
                'mac_address' => $tipe === 'ONT'
                    ? 'AA:BB:CC:DD:00'.$layanan->id
                    : 'AB:CD:EF:12:34'.$layanan->id,
                'merek' => 'Huawei',
                'tipe' => $tipe,
                'status' => StatusPerangkatEnum::TERPASANG,
            ]);
        }
    }

    private function buatTagihan(LayananInternet $layanan, int $jumlahBulan, ?Carbon $periode = null): Tagihan
    {
        $periode = $periode ?? Carbon::today();
        $harga = (float) ($layanan->tipe_paket === TipePaketEnum::CUSTOM
            ? $layanan->harga_custom
            : $layanan->paketInternet->harga);

        return Tagihan::create([
            'nomor_tagihan' => $this->generator->generate(Tagihan::class, 'nomor_tagihan', 'INV'),
            'layanan_internet_id' => $layanan->id,
            'periode_bulan' => $periode->month,
            'periode_tahun' => $periode->year,
            'nama_paket_snapshot' => $layanan->nama_paket_custom ?? $layanan->paketInternet->nama_paket,
            'kecepatan_snapshot_mbps' => $layanan->kecepatan_custom_mbps ?? $layanan->paketInternet->kecepatan_mbps,
            'harga_snapshot' => $harga,
            'total_tagihan' => round($harga * $jumlahBulan, 2),
            'jumlah_bulan' => $jumlahBulan,
            'status_pembayaran' => StatusPembayaranEnum::BELUM_BAYAR,
        ]);
    }

    /**
     * Pembayaran tunai yang mengalokasikan penuh/parsial ke satu tagihan.
     * Kelebihan bayar otomatis dicatat sebagai saldo kredit (deposit).
     */
    private function tunai(Pelanggan $pelanggan, Tagihan $tagihan, float $jumlahDibayar, Carbon $dibayarPada): Pembayaran
    {
        $alokasi = min($jumlahDibayar, (float) $tagihan->total_tagihan);

        $pembayaran = Pembayaran::create([
            'pelanggan_id' => $pelanggan->id,
            'tagihan_id' => null,
            'metode_pembayaran' => 'tunai',
            'dibayar_oleh' => $this->keuangan->nama_lengkap,
            'jumlah_dibayar' => round($jumlahDibayar, 2),
            'tagihan_terpilih' => [$tagihan->id],
            'status' => StatusTransaksiEnum::BERHASIL,
            'dibayar_pada' => $dibayarPada,
        ]);

        if ($alokasi > 0) {
            PembayaranTagihan::create([
                'pembayaran_id' => $pembayaran->id,
                'tagihan_id' => $tagihan->id,
                'jumlah_dialokasikan' => round($alokasi, 2),
            ]);
        }

        $this->akuiTagihan($tagihan, $alokasi, $dibayarPada);

        if ($jumlahDibayar > $alokasi) {
            MutasiSaldoKredit::create([
                'pelanggan_id' => $pelanggan->id,
                'pembayaran_id' => $pembayaran->id,
                'tagihan_id' => null,
                'jenis' => 'kredit',
                'jumlah' => round($jumlahDibayar - $alokasi, 2),
                'keterangan' => 'Kelebihan pembayaran otomatis menjadi saldo kredit pelanggan.',
            ]);
        }

        return $pembayaran;
    }

    /**
     * Pembayaran Xendit gabungan: satu pembayaran mengalokasikan ke satu atau
     * beberapa tagihan (oldest-first). Kelebihan -> saldo kredit.
     *
     * @param  Tagihan[]  $tagihan
     */
    private function xendit(Pelanggan $pelanggan, array $tagihan, float $jumlahDibayar, Carbon $dibayarPada): Pembayaran
    {
        $sisa = $jumlahDibayar;

        $pembayaran = Pembayaran::create([
            'pelanggan_id' => $pelanggan->id,
            'tagihan_id' => null,
            'metode_pembayaran' => 'xendit',
            'provider' => 'xendit',
            'provider_reference' => 'demo-'.strtolower(Str::random(10)),
            'provider_status' => 'PAID',
            'jumlah_dibayar' => round($jumlahDibayar, 2),
            'tagihan_terpilih' => array_map(fn ($t) => $t->id, $tagihan),
            'status' => StatusTransaksiEnum::BERHASIL,
            'dibayar_pada' => $dibayarPada,
        ]);

        foreach ($tagihan as $item) {
            if ($sisa <= 0) {
                break;
            }

            $alokasi = min($sisa, (float) $item->total_tagihan);
            PembayaranTagihan::create([
                'pembayaran_id' => $pembayaran->id,
                'tagihan_id' => $item->id,
                'jumlah_dialokasikan' => round($alokasi, 2),
            ]);

            $this->akuiTagihan($item, $alokasi, $dibayarPada);
            $sisa = round($sisa - $alokasi, 2);
        }

        if ($sisa > 0) {
            MutasiSaldoKredit::create([
                'pelanggan_id' => $pelanggan->id,
                'pembayaran_id' => $pembayaran->id,
                'tagihan_id' => null,
                'jenis' => 'kredit',
                'jumlah' => round($sisa, 2),
                'keterangan' => 'Kelebihan pembayaran otomatis menjadi saldo kredit pelanggan.',
            ]);
        }

        return $pembayaran;
    }

    /** Saldo kredit awal (deposit) tanpa pembayaran terkait. */
    private function deposit(Pelanggan $pelanggan, float $jumlah, Carbon $dibayarPada): void
    {
        MutasiSaldoKredit::create([
            'pelanggan_id' => $pelanggan->id,
            'pembayaran_id' => null,
            'tagihan_id' => null,
            'jenis' => 'kredit',
            'jumlah' => round($jumlah, 2),
            'keterangan' => 'Deposit awal (saldo kredit pelanggan).',
            'created_at' => $dibayarPada,
        ]);
    }

    /** Pemakaian saldo kredit untuk melunasi (sebagian) tagihan. */
    private function pakaiSaldoKredit(Pelanggan $pelanggan, Tagihan $tagihan, float $jumlah): void
    {
        MutasiSaldoKredit::create([
            'pelanggan_id' => $pelanggan->id,
            'pembayaran_id' => null,
            'tagihan_id' => $tagihan->id,
            'jenis' => 'pemakaian',
            'jumlah' => round($jumlah, 2),
            'keterangan' => 'Saldo kredit pelanggan digunakan untuk pembayaran tagihan.',
        ]);

        $this->akuiTagihan($tagihan, $jumlah, Carbon::now());
    }

    /** Update status tagihan sesuai alokasi yang masuk + catat dibayar_pada. */
    private function akuiTagihan(Tagihan $tagihan, float $alokasi, Carbon $dibayarPada): void
    {
        $terbayar = $alokasi > 0 ? $alokasi : 0;

        $tagihan->update([
            'status_pembayaran' => $terbayar >= (float) $tagihan->total_tagihan
                ? StatusPembayaranEnum::SUDAH_BAYAR
                : StatusPembayaranEnum::BELUM_BAYAR,
            'dibayar_pada' => $terbayar >= (float) $tagihan->total_tagihan ? $dibayarPada : null,
        ]);
    }

    // ------------------------------------------------------------------
    // Phase 4 — Permohonan tambahan (operasional) & Laporan Kendala
    // ------------------------------------------------------------------
    private function seedPermohonanTambahan(): void
    {
        $paket = PaketInternet::where('status_aktif', true)->get();
        $sasaran = Pelanggan::whereNull('reseller_id')->inRandomOrder()->limit(3)->get();

        // 1. MENUNGGU_VERIFIKASI — daftar permohonan operasional.
        PermohonanLayanan::create([
            'nomor_permohonan' => $this->generator->generate(PermohonanLayanan::class, 'nomor_permohonan', 'PMH'),
            'pelanggan_id' => $sasaran[0]->id,
            'jenis_permohonan' => JenisPermohonanEnum::PEMASANGAN_BARU,
            'paket_internet_id' => $paket->first()->id,
            'tipe_paket' => TipePaketEnum::REGULER,
            'alamat_pemasangan' => self::ALAMAT[array_rand(self::ALAMAT)],
            'detail_alamat' => 'Pertigaan dekat tiang listrik no. 12',
            'latitude' => -7.79,
            'longitude' => 110.36,
            'status' => StatusPermohonanEnum::MENUNGGU_VERIFIKASI,
        ]);

        // 2. DITERIMA namun belum dijadwalkan.
        $diterima = PermohonanLayanan::create([
            'nomor_permohonan' => $this->generator->generate(PermohonanLayanan::class, 'nomor_permohonan', 'PMH'),
            'pelanggan_id' => $sasaran[1]->id,
            'jenis_permohonan' => JenisPermohonanEnum::PEMASANGAN_BARU,
            'paket_internet_id' => $paket->last()->id,
            'tipe_paket' => TipePaketEnum::REGULER,
            'alamat_pemasangan' => self::ALAMAT[array_rand(self::ALAMAT)],
            'detail_alamat' => 'Belakang pom bensin umum',
            'latitude' => -7.79,
            'longitude' => 110.36,
            'status' => StatusPermohonanEnum::DITERIMA,
            'diproses_oleh' => $this->operasional->id,
        ]);
        $this->catatRiwayat($diterima, null, StatusPermohonanEnum::MENUNGGU_VERIFIKASI, 'Permohonan diajukan.');
        $this->catatRiwayat($diterima, StatusPermohonanEnum::MENUNGGU_VERIFIKASI, StatusPermohonanEnum::DITERIMA, 'Data verifikasi sesuai.');

        // 3. DIJADWALKAN + jadwal kerja mendatang.
        $dijadwalkan = PermohonanLayanan::create([
            'nomor_permohonan' => $this->generator->generate(PermohonanLayanan::class, 'nomor_permohonan', 'PMH'),
            'pelanggan_id' => $sasaran[2]->id,
            'jenis_permohonan' => JenisPermohonanEnum::PEMASANGAN_BARU,
            'paket_internet_id' => $paket->first()->id,
            'tipe_paket' => TipePaketEnum::REGULER,
            'alamat_pemasangan' => self::ALAMAT[array_rand(self::ALAMAT)],
            'detail_alamat' => 'Dekat SDN, masuk gang ketiga',
            'latitude' => -7.79,
            'longitude' => 110.36,
            'status' => StatusPermohonanEnum::DIJADWALKAN,
            'diproses_oleh' => $this->operasional->id,
        ]);
        $this->catatRiwayat($dijadwalkan, null, StatusPermohonanEnum::MENUNGGU_VERIFIKASI, 'Permohonan diajukan.');
        $this->catatRiwayat($dijadwalkan, StatusPermohonanEnum::MENUNGGU_VERIFIKASI, StatusPermohonanEnum::DITERIMA, 'Data verifikasi sesuai.');
        $this->catatRiwayat($dijadwalkan, StatusPermohonanEnum::DITERIMA, StatusPermohonanEnum::DIJADWALKAN, 'Pekerjaan dijadwalkan.');
        $this->buatJadwalKerja($dijadwalkan, Carbon::today()->addDays(2), null, null, $this->tim[0]);
    }

    private function seedLaporanKendala(): void
    {
        $layanan = LayananInternet::with('pelanggan')->where('status', StatusLayananEnum::AKTIF)->get();

        $skenario = [
            [1, StatusLaporanEnum::MENUNGGU],
            [1, StatusLaporanEnum::DIPROSES],
            [1, StatusLaporanEnum::SELESAI],
        ];

        foreach ($skenario as [$jumlah, $status]) {
            for ($i = 0; $i < $jumlah; $i++) {
                $target = $layanan->random(1)->first();
                $buat = [
                    'nomor_laporan' => $this->generator->generate(LaporanKendala::class, 'nomor_laporan', 'LPR'),
                    'layanan_internet_id' => $target->id,
                    'kategori_kendala' => 'Internet Lambat',
                    'deskripsi' => 'Koneksi sangat lambat setiap malam sekitar jam 21.00.',
                    'foto' => null,
                    'status' => $status,
                ];

                if ($status === StatusLaporanEnum::SELESAI) {
                    $buat['ditugaskan_ke'] = $this->teknisi[array_rand($this->teknisi)]->id;
                    $buat['hasil_penanganan'] = 'Kabel connector diganti, signal sudah normal kembali.';
                }
                if ($status === StatusLaporanEnum::DIPROSES) {
                    $buat['ditugaskan_ke'] = $this->teknisi[array_rand($this->teknisi)]->id;
                }

                LaporanKendala::create($buat);
            }
        }
    }

    private function buatJadwalKerja(
        PermohonanLayanan $permohonan,
        Carbon $tanggal,
        ?HasilKerjaEnum $hasil,
        ?string $catatanKendala,
        TimTeknisi $tim
    ): JadwalKerja {
        $jadwal = JadwalKerja::create([
            'permohonan_layanan_id' => $permohonan->id,
            'tim_teknisi_id' => $tim->id,
            'tanggal_kerja' => $tanggal->toDateString(),
            'hasil' => $hasil,
            'catatan_kendala' => $catatanKendala,
            'foto_dokumentasi' => null,
            'latitude_hasil' => -7.79,
            'longitude_hasil' => 110.36,
            'diisi_oleh' => $hasil ? $this->teknisi[array_rand($this->teknisi)]->id : null,
        ]);
        $jadwal->teknisi()->sync([$this->teknisi[array_rand($this->teknisi)]->id]);

        return $jadwal;
    }

    private function catatRiwayat(
        PermohonanLayanan $permohonan,
        ?StatusPermohonanEnum $sebelum,
        StatusPermohonanEnum $sesudah,
        string $catatan = ''
    ): void {
        RiwayatStatusPermohonan::create([
            'permohonan_layanan_id' => $permohonan->id,
            'status_sebelumnya' => $sebelum?->value,
            'status_sesudahnya' => $sesudah->value,
            'diubah_oleh' => $this->operasional->id,
            'catatan' => $catatan,
        ]);
    }

    // ------------------------------------------------------------------
    // Phase 5 — Notifikasi unread untuk badge merah
    // ------------------------------------------------------------------
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

        \DB::table('notifications')
            ->where('notifiable_id', $this->adminUtama->id)
            ->whereNull('read_at')
            ->whereBetween('created_at', [now()->subMinutes(10), now()])
            ->update(['created_at' => now()->subHours(6)->subMinutes(30)]);
    }

    private function snapKeBulan(Carbon $tanggal, int $hariDasar): Carbon
    {
        $hari = min(max(1, $hariDasar), 31);

        return $tanggal->copy()->setDay(min($hari, $tanggal->daysInMonth));
    }
}