<?php

namespace Database\Seeders;

use App\Enums\JenisPermohonanEnum;
use App\Enums\PeranAdminEnum;
use App\Enums\StatusLaporanEnum;
use App\Enums\StatusLayananEnum;
use App\Enums\StatusPermohonanEnum;
use App\Enums\StatusPerangkatEnum;
use App\Enums\TipePaketEnum;
use App\Models\Admin;
use App\Models\JadwalKerja;
use App\Models\LaporanKendala;
use App\Models\LayananInternet;
use App\Models\PaketInternet;
use App\Models\Pelanggan;
use App\Models\Perangkat;
use App\Models\PermohonanLayanan;
use App\Models\RiwayatStatusPermohonan;
use App\Models\TimTeknisi;
use App\Services\GeneratorNomorService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * PelangganSeeder — data master presentasi Sicakra:
 * akun admin/tim, pelanggan, layanan, perangkat, permohonan, laporan kendala,
 * dan jadwal kerja. Tidak menyentuh tagihan/pembayaran — itu urusan DemoSeeder.
 */
class PelangganSeeder extends Seeder
{
    private const ALAMAT = [
        'Jalan Parangtritis, Ngestiharjo, Kasihan, Bantul, Daerah Istimewa Yogyakarta, 55182, Indonesia',
        'Jalan Kaliurang, Caturtunggal, Depok, Sleman, Daerah Istimewa Yogyakarta, 55281, Indonesia',
        'Jalan Monjali, Sinduadi, Mlati, Sleman, Daerah Istimewa Yogyakarta, 55284, Indonesia',
        'Jalan Wonosari, Potorono, Banguntapan, Bantul, Daerah Istimewa Yogyakarta, 55182, Indonesia',
        'Jalan Godean, Trihanggo, Gamping, Sleman, Daerah Istimewa Yogyakarta, 55291, Indonesia',
        'Jalan Magelang, Sendangadi, Mlati, Sleman, Daerah Istimewa Yogyakarta, 55285, Indonesia',
    ];

    private GeneratorNomorService $generator;

    private Admin $operasional;

    private Admin $keuangan;

    private Admin $reseller;

    private array $teknisi = [];

    private array $tim = [];

    private array $pelanggan = [];

    public function run(): void
    {
        if (Pelanggan::exists()) {
            $this->command->warn('Data pelanggan sudah ada, dilewati.');

            return;
        }

        $this->generator = new GeneratorNomorService;

        $this->command->info('Admin & Tim Teknisi');
        $this->seedAdminDanTim();

        $this->command->info('Pelanggan & Layanan (15 pelanggan)');
        $this->seedPelangganDanLayanan();

        $this->command->info('Permohonan tambahan & Laporan Kendala');
        $this->seedPermohonanTambahan();
        $this->seedLaporanKendala();

        $this->command->info('PelangganSeeder selesai.');
    }

    private function seedAdminDanTim(): void
    {
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

    private function seedPelangganDanLayanan(): void
    {
        $silver = PaketInternet::where('nama_paket', 'Paket Silver')->firstOrFail();
        $gold = PaketInternet::where('nama_paket', 'Paket Gold')->firstOrFail();
        $bronze = PaketInternet::where('nama_paket', 'Paket Bronze')->firstOrFail();

        $this->pelanggan['budi'] = $this->buatPelanggan('Budi Santoso', 'budi', '081200000001', '340101010100001');
        $this->buatLayanan($this->pelanggan['budi'], $silver, Carbon::today()->subMonths(4));

        $this->pelanggan['siti'] = $this->buatPelanggan('Siti Nurhaliza', 'siti', '081200000002', '340202020200002');
        $this->buatLayanan($this->pelanggan['siti'], $gold, Carbon::today()->subMonths(6));

        $this->pelanggan['agus'] = $this->buatPelanggan('Agus Wibowo', 'agus', '081200000003', '340303030300003');
        $this->buatLayanan($this->pelanggan['agus'], $silver, Carbon::today()->subMonths(3));

        $this->pelanggan['dewi'] = $this->buatPelanggan('Dewi Lestari', 'dewi', '081200000004', '340404040400004');
        $this->buatLayananCustom($this->pelanggan['dewi'], 'Paket Bisnis 300', 300, 300000, Carbon::today()->subMonths(5));

        $this->pelanggan['wahyu'] = $this->buatPelanggan('Wahyu Nugroho', 'wahyu', '081200000005', '340505050500005');
        $this->buatLayananCustom($this->pelanggan['wahyu'], 'Paket Reguler 300', 300, 300000, Carbon::today()->subMonths(6));

        $this->pelanggan['nia'] = $this->buatPelanggan('Nia Rahmawati', 'nia', '081200000006', '340606060600006');
        $this->buatLayananCustom($this->pelanggan['nia'], 'Paket Rumah A', 100, 100000, Carbon::today()->subMonths(5));
        $this->buatLayananCustom($this->pelanggan['nia'], 'Paket Rumah B', 150, 150000, Carbon::today()->subMonths(4));
        $this->buatLayananCustom($this->pelanggan['nia'], 'Paket Rumah C', 200, 200000, Carbon::today()->subMonths(3));

        $this->pelanggan['sri'] = $this->buatPelanggan('Sri Rahayu', 'sri', '081200000007', '340707070700007');
        $this->buatLayananCustom($this->pelanggan['sri'], 'Paket Kantor Sri 300', 300, 300000, Carbon::today()->subMonths(5));

        $this->pelanggan['joko'] = $this->buatPelanggan('Joko Susilo', 'joko', '081200000008', '340808080800008');
        $this->buatLayanan($this->pelanggan['joko'], $bronze, Carbon::today()->subMonths(4));
        $this->buatLayananCustom($this->pelanggan['joko'], 'Paket Toko Joko 300', 300, 300000, Carbon::today()->subMonths(2));

        $this->pelanggan['rina'] = $this->buatPelanggan('Rina Marlina', 'rina', '081200000009', '340909090900009');
        $this->buatLayananCustom($this->pelanggan['rina'], 'Paket Rina 100', 100, 100000, Carbon::today()->subMonths(6));
        $this->buatLayananCustom($this->pelanggan['rina'], 'Paket Rina Cadangan 300', 300, 300000, Carbon::today()->subMonths(2));

        $this->pelanggan['fajar'] = $this->buatPelanggan('Fajar Ramadhan', 'fajar', '081200000010', '341010101000010');
        $this->buatLayananCustom($this->pelanggan['fajar'], 'Paket Usaha Fajar 250', 250, 250000, Carbon::today()->subMonths(3));

        // Pelanggan milik reseller.
        $this->pelanggan['putra'] = $this->buatPelanggan('Putra Madani', 'putra', '081200000011', '341111111100011', $this->reseller);
        $this->buatLayananCustom($this->pelanggan['putra'], 'Paket Putra 300', 300, 300000, Carbon::today()->subMonths(3));

        $this->pelanggan['ilham'] = $this->buatPelanggan('Ilham Nusantara', 'ilham', '081200000012', '341212121200012', $this->reseller);
        $this->buatLayanan($this->pelanggan['ilham'], $gold, Carbon::today()->subMonths(2));

        $this->pelanggan['bagas'] = $this->buatPelanggan('Bagas Pratama', 'bagas', '081200000013', '341313131300013', $this->reseller);
        $this->buatLayananCustom($this->pelanggan['bagas'], 'Paket Warung Bagas 300', 300, 300000, Carbon::today()->subMonths(2));

        $this->pelanggan['sulton'] = $this->buatPelanggan('Sulton Baru', 'sulton', '081200000014', '341414141400014', $this->reseller);
        $this->buatLayanan($this->pelanggan['sulton'], $bronze, Carbon::today()->subMonth());

        // Pelanggan baru: layanan aktif, belum ada tagihan.
        $this->pelanggan['hendra'] = $this->buatPelanggan('Hendra Wijaya', 'hendra', '081200000015', '341515151500015');
        $this->buatLayanan($this->pelanggan['hendra'], $bronze, Carbon::today()->subDays(3));
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

        // Backdate konversi ke tanggal aktif.
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

        $layanan = LayananInternet::create([
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

        $this->buatPerangkat($layanan);

        return $layanan;
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
        ?\App\Enums\HasilKerjaEnum $hasil,
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

    private function snapKeBulan(Carbon $tanggal, int $hariDasar): Carbon
    {
        $hari = min(max(1, $hariDasar), 31);

        return $tanggal->copy()->setDay(min($hari, $tanggal->daysInMonth));
    }
}