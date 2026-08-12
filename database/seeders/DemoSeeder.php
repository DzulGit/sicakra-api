<?php

namespace Database\Seeders;

use App\Enums\HasilKerjaEnum;
use App\Enums\JenisPermohonanEnum;
use App\Enums\JenisPerubahanPaketEnum;
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
use App\Models\Perangkat;
use App\Models\PermohonanLayanan;
use App\Models\RiwayatPerubahanPaket;
use App\Models\RiwayatRelokasi;
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
 * DemoSeeder — data presentasi lengkap untuk alur bisnis Sicakra.
 *
 * Urutan seed (aman dari FK violation, dari master data ke transaksi):
 *   1. Admin (Super Admin + peran bisnis) → TimTeknisi + anggota
 *   2. Paket (master layanan)
 *   3. Pelanggan (akun + atribut penagihan)
 *   4. PermohonanLayanan (asal usul) → LayananInternet → Perangkat
 *   5. LaporanKendala (tiket gangguan)
 *   6. Tagihan (periode berjalan) → Pembayaran
 *   7. Permohonan tambahan utk semua jenis (PB/Tambah/Ganti/Relokasi) + JadwalKerja
 *   8. Notifikasi in-app unread ke Admin Utama (badge nyala)
 */
class DemoSeeder extends Seeder
{
    private const DEMO_ADMIN_NAME = 'Admin Utama Demo';

    // Kecamatan se-DIY (Bantul + Sleman) dengan kelurahan & kode pos asli.
    private const KECAMATAN = [
        'Kasihan (Bantul)' => [
            ['Ngestiharjo', '55182'],
            ['Tamantirto', '55183'],
            ['Tirtonirmolo', '55181'],
            ['Bangunjiwo', '55184'],
            ['Madurejo', '55186'],
        ],
        'Sewon (Bantul)' => [
            ['Panggungharjo', '55188'],
            ['Timbulharjo', '55184'],
            ['Bangunharjo', '55198'],
            ['Pendowoharjo', '55193'],
        ],
        'Banguntapan (Bantul)' => [
            ['Baturetno', '55198'],
            ['Banguntapan', '55198'],
            ['Jambidan', '55198'],
            ['Potorono', '55182'],
        ],
        'Depok (Sleman)' => [
            ['Caturtunggal', '55281'],
            ['Condongcatur', '55283'],
            ['Maguwoharjo', '55282'],
        ],
        'Mlati (Sleman)' => [
            ['Sinduadi', '55284'],
            ['Tlogoadi', '55286'],
            ['Sendangadi', '55285'],
        ],
        'Gamping (Sleman)' => [
            ['Banyuraden', '55294'],
            ['Nogotirto', '55292'],
            ['Trihanggo', '55291'],
        ],
    ];

    private const JALAN = [
        'Parangtritis', 'Imogiri Timur', 'Bantul', 'KH Ali Maksum Krapyak', 'Wonocatur',
        'Wonosari', 'Kaliurang', 'Monjali', 'Godean', 'Magelang', 'Kusumanegara',
        'Veteran', 'Laksda Adisucipto', 'Ringroad Selatan', 'Ringroad Utara',
        'Seturan', 'Janti', 'Nogokerten', 'Slamet Riyadi', 'Kasongan',
        'Pandega Marta', 'Gejayan', 'Demangan Baru', 'Karang Malang', 'Tambak Bayan',
    ];

    private const LANDMARK = [
        'Pagar hitam depan warung Madura',
        'Masuk gang samping masjid cat hijau',
        'Rumah tingkat cat putih',
        'Depan minimarket, seberang apotek',
        'Pertigaan dekat tiang listrik no. 12',
        'Gang sempit, sepeda motor harus parkir depan',
        'Dekat SDN, masuk gang ketiga',
        'Belakang pom bensin umum',
        'Samping lapangan voli',
        'Tepat di sebelah kafe kopi',
    ];

    private const KATEGORI_KENDALA = ['Tidak Ada Sinyal', 'Internet Lambat', 'Perangkat Rusak', 'Kabel Putus', 'Gangguan Regional'];

    private const KELUHAN = [
        'Internet LOS merah terus-menerus sejak pagi',
        'Koneksi sangat lambat setiap malam sekitar jam 21.00',
        'Kabel putus tertimpa dahan pohon setelah hujan deras',
        'ONU mati total, lampu PON tidak menyala',
        'Sinyal putus-putus, browsing sering timeout',
    ];

    private const NAMA_PELANGGAN = [
        'Slamet Riyadi', 'Siti Nurhaliza', 'Budi Santoso', 'Agus Wibowo', 'Dewi Lestari',
        'Wahyu Nugroho', 'Sri Rahayu', 'Joko Susilo', 'Rini Puspitasari', 'Andi Prasetyo',
        'Rina Marlina', 'Eko Prasetyo', 'Tuti Herawati', 'Bambang Santoso', 'Yuli Astuti',
        'Hendra Gunawan', 'Nurul Hidayah', 'Fajar Ramadhan', 'Lestari Handayani', 'Dimas Anggara',
        'Maya Sari', 'Ardian Putra', 'Retno Wulandari', 'Galih Priambodo', 'Anisa Fitriani',
        'Doni Kurniawan', 'Ratna Dewi', 'Yusuf Maulana', 'Indah Pratiwi', 'Rudi Hartono',
        'Sari Marlina', 'Teguh Santoso', 'Wulan Sari', 'Bayu Aji', 'Citra Ayu',
        'Dedi Setiawan', 'Fitri Handayani', 'Gunawan Wijaya', 'Hesti Purnamasari', 'Iskandar Zulkarnain',
    ];

    // Indeks pelanggan yang punya LEBIH DARI 1 paket aktif (rumah + usaha/toko).
    private const PELANGGAN_MULTI_PAKET = [2, 9, 21, 37];

    private const GARASI_BISNIS = ['Toko Sembako', 'Toko Elektronik', 'Warkop & Katering', 'Studio Foto & Percetakan'];

    private GeneratorNomorService $generator;

    private Admin $adminUtama;

    private Admin $operasional;

    private Admin $keuangan;

    private array $teknisi = [];

    private array $tim = [];

    public function run(): void
    {
        // Guard agar tidak dobel saat demo di-seed ulang.
        if (Admin::where('nama_lengkap', self::DEMO_ADMIN_NAME)->exists()) {
            $this->command->warn('Data demo sudah pernah di-seed, dilewati.');

            return;
        }

        $this->generator = new GeneratorNomorService;

        $this->command->info('Phase 1 — Admin & Tim Teknisi');
        $this->seedAdminDanTim();

        $this->command->info('Phase 2 — Paket Internet (master)');
        $this->call(PaketInternetSeeder::class);

        $this->command->info('Phase 3 — Pelanggan + Layanan');
        $this->seedPelanggan();

        $this->command->info('Phase 4 — Laporan Kendala');
        $this->seedLaporanKendala();

        $this->command->info('Phase 5 — Tagihan & Pembayaran');
        $this->seedTagihan();

        $this->command->info('Phase 6 — Permohonan & Jadwal Kerja (semua jenis)');
        $this->seedPermohonanTambahan();

        $this->command->info('Phase 7 — Notifikasi unread ke Admin Utama');
        $this->seedNotifikasi();

        $this->command->info('DemoSeeder selesai. Pelanggan dapat login dengan username dan password "password123".');
    }

    // ------------------------------------------------------------------
    // Phase 1 — Admin + Tim
    // ------------------------------------------------------------------
    private function seedAdminDanTim(): void
    {
        $this->adminUtama = Admin::updateOrCreate([
            'email' => 'admin@sicakra.com',
        ], [
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

        $keuanganAdm = Admin::updateOrCreate(['email' => 'keuangan@sicakra.com'], [
            'nama_lengkap' => 'Kirana Keuangan',
            'password' => 'password123',
            'peran' => PeranAdminEnum::KEUANGAN,
            'status_aktif' => true,
        ]);
        $this->keuangan = $keuanganAdm;

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
    // Phase 3 — Pelanggan & layanan inti
    // ------------------------------------------------------------------
    private function seedPelanggan(): void
    {
        foreach (self::NAMA_PELANGGAN as $idx => $nama) {
            $pelanggan = $this->buatPelanggan($idx, $nama);
            $alamatUtama = $this->alamatJogja();

            // Layanan utama = rumah.
            $this->buatLayananDariPermohonan($pelanggan, $alamatUtama, JenisPermohonanEnum::PEMASANGAN_BARU, now()->subMonths(mt_rand(2, 5)));

            // Sebagian pelanggan punya layanan kedua (usaha/toko).
            if (in_array($idx, self::PELANGGAN_MULTI_PAKET, true)) {
                $usaha = self::GARASI_BISNIS[array_rand(self::GARASI_BISNIS)];
                $alamatUsaha = $this->alamatJogja(true);
                // Tambah Paket = layanan terpisah second, aktif belakangan.
                $this->buatLayananDariPermohonan($pelanggan, $alamatUsaha, JenisPermohonanEnum::TAMBAH_PAKET, now()->subMonths(mt_rand(1, 2)), $usaha);
            }
        }
    }

    private function buatPelanggan(int $idx, string $nama): Pelanggan
    {
        mt_srand(2000 + $idx);

        $username = strtolower(preg_replace('/[^a-z0-9]+/i', '', $nama)).'_'.$idx;
        $nomorPlg = $this->generator->generate(Pelanggan::class, 'nomor_pelanggan', 'PLG', true);

        return Pelanggan::create([
            'nomor_pelanggan' => $nomorPlg,
            'username' => $username,
            'nama_lengkap' => $nama,
            'nik' => '34'.($idx % 2 === 0 ? '02' : '04').mt_rand(010106, 991231).mt_rand(0001, 9999),
            'nomor_hp' => '08'.mt_rand(100000000, 999999999),
            'email' => $username.'@sicakra-demo.com',
            'password' => 'password123',
            'password_sudah_dibuat' => true,
            'tanggal_tagihan' => mt_rand(1, 3) * 5, // 5/10/15 — membuat variasi jatuh tempo
            'foto_ktp' => 'ktp/dummy.jpg',
            'foto_selfie_ktp' => 'selfie-ktp/dummy.jpg',
        ]);
    }

    /**
     * Alamat format wajib Jogja:
     * "Jalan {Nama Jalan}, {Kelurahan}, {Kecamatan}, {Kabupaten}, Daerah Istimewa Yogyakarta, {KodePos}, Indonesia".
     */
    private function alamatJogja(bool $komersial = false): array
    {
        $kecTerkunci = $komersial ? ['Depok (Sleman)', 'Mlati (Sleman)'] : array_keys(self::KECAMATAN);
        $kecNama = $kecTerkunci[array_rand($kecTerkunci)];
        $kabupaten = str_contains($kecNama, 'Bantul') ? 'Bantul' : 'Sleman';

        [$kelurahan, $kodePos] = self::KECAMATAN[$kecNama][array_rand(self::KECAMATAN[$kecNama])];
        $kecamatan = explode(' (', $kecNama)[0];

        return [
            'alamat_pemasangan' => sprintf(
                'Jalan %s, %s, %s, %s, Daerah Istimewa Yogyakarta, %s, Indonesia',
                self::JALAN[array_rand(self::JALAN)], $kelurahan, $kecamatan, $kabupaten, $kodePos
            ),
            'detail_alamat' => self::LANDMARK[array_rand(self::LANDMARK)],
            // Pusat kota Yogyakarta: lat -7.79, lng 110.36 ± random.
            'latitude' => number_format(-7.79 + (mt_rand(-500, 500) / 100000), 7),
            'longitude' => number_format(110.36 + (mt_rand(-500, 500) / 100000), 7),
        ];
    }

    private function buatLayananDariPermohonan(
        Pelanggan $pelanggan,
        array $alamat,
        JenisPermohonanEnum $jenis,
        Carbon $tanggalAktif,
        ?string $catatanCustom = null
    ): LayananInternet {
        $paket = PaketInternet::where('status_aktif', true)
            ->where('nama_paket', 'not like', '%Promo%')
            ->inRandomOrder()->first() ?? PaketInternet::first();

        $permohonan = PermohonanLayanan::create([
            'nomor_permohonan' => $this->generator->generate(PermohonanLayanan::class, 'nomor_permohonan', 'PMH'),
            'pelanggan_id' => $pelanggan->id,
            'jenis_permohonan' => $jenis,
            'paket_internet_id' => $paket->id,
            'tipe_paket' => TipePaketEnum::REGULER,
            'catatan_custom' => $catatanCustom,
            'alamat_pemasangan' => $alamat['alamat_pemasangan'],
            'detail_alamat' => $alamat['detail_alamat'],
            'latitude' => $alamat['latitude'],
            'longitude' => $alamat['longitude'],
            'status' => StatusPermohonanEnum::DIKONVERSI,
            'diproses_oleh' => $this->operasional->id,
        ]);

        // Jejak alur status: MENUNGGU → DITERIMA → DIJADWALKAN → DIKONVERSI.
        $this->catatRiwayat($permohonan, null, StatusPermohonanEnum::MENUNGGU_VERIFIKASI, 'Permohonan diajukan.');
        $this->catatRiwayat($permohonan, StatusPermohonanEnum::MENUNGGU_VERIFIKASI, StatusPermohonanEnum::DITERIMA, 'Data verifikasi sesuai.');
        $this->catatRiwayat($permohonan, StatusPermohonanEnum::DITERIMA, StatusPermohonanEnum::DIJADWALKAN, 'Pekerjaan dijadwalkan.');
        $this->catatRiwayat($permohonan, StatusPermohonanEnum::DIJADWALKAN, StatusPermohonanEnum::DIKONVERSI, 'Pemasangan selesai, layanan aktif.');

        // Jadwal teknis historis untuk permohonan yang sudah terkonversi.
        $this->buatJadwalKerja($permohonan, $tanggalAktif->copy()->addDay(), HasilKerjaEnum::SELESAI, null,
            $this->tim[array_rand($this->tim)], 'Dokumentasi pemasangan.');
        $permohonan->riwayatStatus()
            ->where('status_sesudahnya', StatusPermohonanEnum::DIKONVERSI->value)
            ->first()?->update(['created_at' => $tanggalAktif->toDateTimeString()]);

        $bebasBulan = (int) ($paket->promo_gratis_bulan ?? 0);

        $layanan = LayananInternet::create([
            'nomor_layanan' => $this->generator->generate(LayananInternet::class, 'nomor_layanan', 'LYN'),
            'permohonan_layanan_id' => $permohonan->id,
            'pelanggan_id' => $pelanggan->id,
            'paket_internet_id' => $paket->id,
            'tipe_paket' => TipePaketEnum::REGULER,
            'alamat_pemasangan' => $alamat['alamat_pemasangan'],
            'detail_alamat' => $alamat['detail_alamat'],
            'latitude' => $alamat['latitude'],
            'longitude' => $alamat['longitude'],
            'status' => StatusLayananEnum::AKTIF,
            'tanggal_aktif' => $tanggalAktif->toDateString(),
            'bebas_tagihan_bulan' => $bebasBulan,
        ]);

        // Jadwal penagihan siklus berikutnya (konsisten dg cron SiklusPenagihanService).
        $layanan->update([
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
        mt_srand($layanan->id);
        foreach (['ONT', 'Router'] as $tipe) {
            Perangkat::create([
                'layanan_internet_id' => $layanan->id,
                'serial_number' => 'SIC-'.$layanan->id.'-'.mt_rand(100000, 999999),
                'mac_address' => sprintf('%02x', mt_rand(0, 255)).sprintf('%02x', mt_rand(0, 255))
                    .':'.str_pad((string) mt_rand(0, 9999), 4, '0', STR_PAD_LEFT)
                    .':'.str_pad((string) mt_rand(0, 9999), 4, '0', STR_PAD_LEFT),
                'merek' => 'Huawei',
                'tipe' => $tipe,
                'status' => StatusPerangkatEnum::TERPASANG,
            ]);
        }
    }

    // ------------------------------------------------------------------
    // Phase 4 — Laporan Kendala (tiket)
    // ------------------------------------------------------------------
    private function seedLaporanKendala(): void
    {
        $layanan = LayananInternet::with('pelanggan')->where('status', StatusLayananEnum::AKTIF)->get();

        $skenario = [
            // [jumlah, status, kategori-clue]
            [2, StatusLaporanEnum::MENUNGGU, 'baru'],
            [2, StatusLaporanEnum::DIPROSES, 'diproses'],
            [2, StatusLaporanEnum::DITUGASKAN, 'ditugaskan'],
            [2, StatusLaporanEnum::SELESAI, 'selesai'],
            [1, StatusLaporanEnum::DITUTUP, 'ditutup'],
        ];

        foreach ($skenario as [$jumlah, $status, $fase]) {
            for ($i = 0; $i < $jumlah; $i++) {
                $target = $layanan->random(1)->first();
                $buat = [
                    'nomor_laporan' => $this->generator->generate(LaporanKendala::class, 'nomor_laporan', 'LPR'),
                    'layanan_internet_id' => $target->id,
                    'kategori_kendala' => self::KATEGORI_KENDALA[array_rand(self::KATEGORI_KENDALA)],
                    'deskripsi' => self::KELUHAN[array_rand(self::KELUHAN)],
                    'foto' => null,
                    'status' => $status,
                ];

                if (in_array($status, [StatusLaporanEnum::DITUGASKAN, StatusLaporanEnum::SELESAI], true)) {
                    $buat['ditugaskan_ke'] = $this->teknisi[array_rand($this->teknisi)]->id;
                }
                if ($status === StatusLaporanEnum::SELESAI) {
                    $buat['hasil_penanganan'] = 'Kabel connector diganti, signal sudah normal kembali.';
                }
                if ($status === StatusLaporanEnum::DITUTUP) {
                    $buat['ditutup_oleh'] = $this->operasional->id;
                    $buat['hasil_penanganan'] = 'Laporan duplikat, sudah ditangani tiket sebelumnya.';
                }

                LaporanKendala::create($buat);
            }
        }
    }

    // ------------------------------------------------------------------
    // Phase 5 — Tagihan & Pembayaran (60/30/10)
    // ------------------------------------------------------------------
    private function seedTagihan(): void
    {
        $layanan = LayananInternet::where('status', StatusLayananEnum::AKTIF)->get();
        $total = $layanan->count();
        $targetPaid = (int) round($total * 0.60);
        $targetUnpaid = (int) round($total * 0.30);

        $layanan->each(function (LayananInternet $l, int $idx) use ($targetPaid, $targetUnpaid) {
            if ($idx < $targetPaid) {
                $this->buatTagihan($l, StatusPembayaranEnum::SUDAH_BAYAR);
            } elseif ($idx < $targetPaid + $targetUnpaid) {
                $this->buatTagihan($l, StatusPembayaranEnum::BELUM_BAYAR);
            } else {
                $this->buatTagihan($l, StatusPembayaranEnum::KEDALUWARSA);
            }
        });
    }

    private function buatTagihan(LayananInternet $layanan, StatusPembayaranEnum $status): Tagihan
    {
        $pelanggan = $layanan->pelanggan;
        $hariTagih = (int) ($pelanggan->tanggal_tagihan ?? 20);
        $paket = $layanan->paketInternet;

        $periode = Carbon::today();
        $jumlahBulan = ($status === StatusPembayaranEnum::SUDAH_BAYAR && $layanan->id % 7 === 0) ? 2 : 1;

        // Siklus jatuh tempo = hari tagih di bulan periode, snapped.
        $jatuhTempo = ($status === StatusPembayaranEnum::KEDALUWARSA)
            ? $this->snapKeBulan($periode->copy()->subMonthNoOverflow(1), $hariTagih)
            : $this->snapKeBulan($periode->copy(), $hariTagih);

        $tagihan = Tagihan::create([
            'nomor_tagihan' => $this->generator->generate(Tagihan::class, 'nomor_tagihan', 'INV'),
            'layanan_internet_id' => $layanan->id,
            'periode_bulan' => $periode->month,
            'periode_tahun' => $periode->year,
            'nama_paket_snapshot' => $paket->nama_paket,
            'kecepatan_snapshot_mbps' => $paket->kecepatan_mbps,
            'harga_snapshot' => $paket->harga,
            'total_tagihan' => $paket->harga * $jumlahBulan,
            'jumlah_bulan' => $jumlahBulan,
            'tanggal_jatuh_tempo' => $jatuhTempo->toDateString(),
            'status_pembayaran' => $status,
        ]);

        switch ($status) {
            case StatusPembayaranEnum::SUDAH_BAYAR:
                $dibayarPada = Carbon::parse($jatuhTempo->toDateString())
                    ->addDays(mt_rand(1, 3))
                    ->setTime(mt_rand(8, 16), mt_rand(0, 59));
                if ($dibayarPada->gt(Carbon::today())) {
                    $dibayarPada = Carbon::today()->subDay()->setTime(10, 15);
                }
                $tagihan->update([
                    'xendit_invoice_status' => 'PAID',
                    'dibayar_pada' => $dibayarPada,
                ]);
                $tagihan->pembayaran()->create([
                    'metode_pembayaran' => $layanan->id % 2 === 0 ? 'xendit' : 'tunai',
                    'dibayar_oleh' => $layanan->id % 2 === 0 ? null : $this->keuangan->nama_lengkap,
                    'jumlah_dibayar' => $tagihan->total_tagihan,
                    'referensi_xendit' => 'demo-'.strtolower(Str::random(10)),
                    'status' => StatusTransaksiEnum::BERHASIL,
                    'dibayar_pada' => $dibayarPada,
                ]);
                break;

            case StatusPembayaranEnum::BELUM_BAYAR:
                $tagihan->update([
                    'xendit_invoice_id' => 'inv_demo_'.strtolower(Str::random(24)),
                    'xendit_external_id' => $tagihan->nomor_tagihan,
                    'xendit_invoice_url' => 'https://checkout.xendit.co/web/demo',
                    'xendit_invoice_status' => 'PENDING',
                    'xendit_invoice_expires_at' => $jatuhTempo->copy()->addDays(1),
                ]);
                break;

            case StatusPembayaranEnum::KEDALUWARSA:
                $tagihan->update([
                    'xendit_invoice_id' => 'inv_demo_'.strtolower(Str::random(24)),
                    'xendit_external_id' => $tagihan->nomor_tagihan,
                    'xendit_invoice_url' => 'https://checkout.xendit.co/web/demo',
                    'xendit_invoice_status' => 'EXPIRED',
                    'xendit_invoice_expires_at' => $jatuhTempo->copy()->subDay(),
                    'xendit_invoice_retry_count' => mt_rand(0, 1),
                    'retry_count' => mt_rand(0, 2),
                ]);
                break;
        }

        return $tagihan;
    }

    // ------------------------------------------------------------------
    // Phase 6 — Permohonan tambahan + Jadwal Kerja (semua jenis)
    // ------------------------------------------------------------------
    private function seedPermohonanTambahan(): void
    {
        $pelanggan = Pelanggan::inRandomOrder()->limit(8)->get();
        $paket = PaketInternet::where('status_aktif', true)->inRandomOrder()->get();
        $paketReguler = $paket->firstWhere('nama_paket', 'Paket Silver') ?? $paket->first();

        // 1. Pemasangan Baru MENUNGGU_VERIFIKASI — tombol "Pendaftar Baru" nyala.
        $baruA = $this->buatPermohonan($pelanggan[0], JenisPermohonanEnum::PEMASANGAN_BARU, $paket->first(), StatusPermohonanEnum::MENUNGGU_VERIFIKASI);
        $baruB = $this->buatPermohonan($pelanggan[1], JenisPermohonanEnum::PEMASANGAN_BARU, $paket->last(), StatusPermohonanEnum::MENUNGGU_VERIFIKASI);

        // 2. Diterima tapi belum dijadwalkan.
        $this->buatPermohonan($pelanggan[2], JenisPermohonanEnum::PEMASANGAN_BARU, $paketReguler, StatusPermohonanEnum::DITERIMA, $this->operasional);

        // 3. DITOLAK + PERLU_REVISI (variasi alur admin).
        $tolak = $this->buatPermohonan($pelanggan[0], JenisPermohonanEnum::PEMASANGAN_BARU, $paket->first(), StatusPermohonanEnum::MENUNGGU_VERIFIKASI);
        $tolak->update(['status' => StatusPermohonanEnum::DITOLAK, 'alasan_ditolak' => 'Alamat tidak sesuai coverage jaringan kami.']);
        $revisi = $this->buatPermohonan($pelanggan[1], JenisPermohonanEnum::PEMASANGAN_BARU, $paketReguler, StatusPermohonanEnum::MENUNGGU_VERIFIKASI);
        $revisi->update(['status' => StatusPermohonanEnum::PERLU_REVISI, 'catatan_custom' => 'Foto KTP kurang jelas, mohon ulangi.']);

        // 4. Empat jenis DIJADWALKAN + jadwal kerja — wakil SEMUA jenis permohonan.
        $a = $this->buatPermohonan($pelanggan[3], JenisPermohonanEnum::PEMASANGAN_BARU, $paket->first(), StatusPermohonanEnum::DITERIMA, $this->operasional);
        $a->update(['status' => StatusPermohonanEnum::DIJADWALKAN]);
        $this->buatJadwalKerja($a, Carbon::today()->addDays(2), null, null, $this->tim[0]);

        $b = $this->buatPermohonan($pelanggan[4], JenisPermohonanEnum::TAMBAH_PAKET, $paket->last(), StatusPermohonanEnum::DITERIMA, $this->operasional);
        $b->update(['status' => StatusPermohonanEnum::DIJADWALKAN]);
        $this->buatJadwalKerja($b, Carbon::today()->addDays(3), null, null, $this->tim[1]);

        // Ganti Paket — jadwal sudah dieksekusi kemarin, permohonan terkonversi.
        $layananGanti = LayananInternet::inRandomOrder()->first();
        $c = $this->buatPermohonan($pelanggan[5], JenisPermohonanEnum::GANTI_PAKET, $paket->first(), StatusPermohonanEnum::DITERIMA, $this->operasional, $layananGanti);
        $c->update(['status' => StatusPermohonanEnum::DIJADWALKAN, 'paket_internet_id_baru' => $paket->last()->id, 'alasan' => 'Kebutuhan bandwidth naik untuk usaha']);
        $this->buatJadwalKerja($c, Carbon::today()->subDay(), HasilKerjaEnum::SELESAI, null, $this->tim[0], 'Dokumentasi ganti paket.');
        $this->konversiGantiPaket($layananGanti, $paket->last());
        $c->update(['status' => StatusPermohonanEnum::DIKONVERSI]);
        $this->catatRiwayat($c, StatusPermohonanEnum::DIJADWALKAN, StatusPermohonanEnum::DIKONVERSI, 'Ganti paket terpasang.');

        // Relokasi — jadwal selesai 2 hari lalu, alamat layanan diperbarui.
        $layananRelokasi = LayananInternet::inRandomOrder()->first();
        $d = $this->buatPermohonan($pelanggan[6], JenisPermohonanEnum::RELOKASI, $paketReguler, StatusPermohonanEnum::DITERIMA, $this->operasional, $layananRelokasi);
        $d->update(['status' => StatusPermohonanEnum::DIJADWALKAN]);
        $this->buatJadwalKerja($d, Carbon::today()->subDays(2), HasilKerjaEnum::SELESAI, null, $this->tim[1], 'Dokumentasi relokasi.');
        $this->konversiRelokasi($layananRelokasi, $d);
        $d->update(['status' => StatusPermohonanEnum::DIKONVERSI]);
        $this->catatRiwayat($d, StatusPermohonanEnum::DIJADWALKAN, StatusPermohonanEnum::DIKONVERSI, 'Relokasi selesai, alamat diperbarui.');

        // 5. DITUNDA — state machine: DIJADWALKAN → (kendala) → DITUNDA → DIJADWALKAN (jadwal baru).
        $tunda = $this->buatPermohonan($pelanggan[7], JenisPermohonanEnum::PEMASANGAN_BARU, $paketReguler, StatusPermohonanEnum::MENUNGGU_VERIFIKASI);
        $tunda->update(['status' => StatusPermohonanEnum::DIJADWALKAN, 'diproses_oleh' => $this->operasional->id]);
        $this->buatJadwalKerja($tunda, Carbon::today()->subDays(4), HasilKerjaEnum::KENDALA, 'Lokasi belum siap, kabel ODCC belum ditarik.', $this->tim[0]);
        $tunda->update([
            'status' => StatusPermohonanEnum::DITUNDA,
            'alasan_ditunda' => 'Pelanggan meminta penjadwalan ulang.',
        ]);
        $this->catatRiwayat($tunda, StatusPermohonanEnum::DIJADWALKAN, StatusPermohonanEnum::DITUNDA, 'Kendala di kunjungan pertama.');
        $tunda->update(['status' => StatusPermohonanEnum::DIJADWALKAN]);
        $this->catatRiwayat($tunda, StatusPermohonanEnum::DITUNDA, StatusPermohonanEnum::DIJADWALKAN, 'Dijadwalkan ulang.');
        $this->buatJadwalKerja($tunda, Carbon::today()->addDays(8), null, null, $this->tim[0]);
    }

    private function buatPermohonan(
        Pelanggan $pelanggan,
        JenisPermohonanEnum $jenis,
        ?PaketInternet $paket,
        StatusPermohonanEnum $status,
        ?Admin $diprosesOleh = null,
        ?LayananInternet $layananReferensi = null
    ): PermohonanLayanan {
        $alamat = $this->alamatJogja($jenis === JenisPermohonanEnum::RELOKASI);

        return PermohonanLayanan::create([
            'nomor_permohonan' => $this->generator->generate(PermohonanLayanan::class, 'nomor_permohonan', 'PMH'),
            'pelanggan_id' => $pelanggan->id,
            'jenis_permohonan' => $jenis,
            'layanan_internet_id' => $layananReferensi?->id,
            'paket_internet_id' => $paket?->id,
            'tipe_paket' => TipePaketEnum::REGULER,
            'alamat_pemasangan' => $layananReferensi?->alamat_pemasangan ?? $alamat['alamat_pemasangan'],
            'detail_alamat' => $layananReferensi?->detail_alamat ?? $alamat['detail_alamat'],
            'latitude' => $layananReferensi?->latitude ?? $alamat['latitude'],
            'longitude' => $layananReferensi?->longitude ?? $alamat['longitude'],
            'status' => $status,
            'diproses_oleh' => $diprosesOleh?->id,
        ]);
    }

    private function buatJadwalKerja(
        PermohonanLayanan $permohonan,
        Carbon $tanggal,
        ?HasilKerjaEnum $hasil,
        ?string $catatanKendala,
        TimTeknisi $tim,
        ?string $dokumentasi = null
    ): JadwalKerja {
        $jadwal = JadwalKerja::create([
            'permohonan_layanan_id' => $permohonan->id,
            'tim_teknisi_id' => $tim->id,
            'tanggal_kerja' => $tanggal->toDateString(),
            'hasil' => $hasil,
            'catatan_kendala' => $catatanKendala,
            'foto_dokumentasi' => $hasil === HasilKerjaEnum::SELESAI || $dokumentasi ? ['dokumentasi/kerja-'.$permohonan->id.'.jpg'] : null,
            'latitude_hasil' => -7.79,
            'longitude_hasil' => 110.36,
            'diisi_oleh' => $hasil ? $this->teknisi[array_rand($this->teknisi)]->id : null,
        ]);
        $jadwal->teknisi()->sync([$this->teknisi[array_rand($this->teknisi)]->id]);

        return $jadwal;
    }

    private function konversiGantiPaket(LayananInternet $layanan, PaketInternet $paketBaru): void
    {
        if (! $layanan || $layanan->paket_internet_id === $paketBaru->id) {
            return;
        }

        RiwayatPerubahanPaket::create([
            'layanan_internet_id' => $layanan->id,
            'nama_paket_lama' => $layanan->paketInternet->nama_paket,
            'kecepatan_lama_mbps' => $layanan->paketInternet->kecepatan_mbps,
            'harga_lama' => $layanan->paketInternet->harga,
            'nama_paket_baru' => $paketBaru->nama_paket,
            'kecepatan_baru_mbps' => $paketBaru->kecepatan_mbps,
            'harga_baru' => $paketBaru->harga,
            'jenis_perubahan' => $paketBaru->harga > $layanan->paketInternet->harga
                ? JenisPerubahanPaketEnum::UPGRADE : JenisPerubahanPaketEnum::DOWNGRADE,
            'diubah_oleh' => $this->operasional->id,
            'tanggal_perubahan' => Carbon::today()->subDay()->toDateString(),
        ]);

        $layanan->update([
            'paket_internet_id' => $paketBaru->id,
        ]);
    }

    private function konversiRelokasi(LayananInternet $layanan, PermohonanLayanan $permohonan): void
    {
        if (! $layanan) {
            return;
        }

        $alamatBaru = $this->alamatJogja();
        $tanggalRelokasi = Carbon::today()->subDays(2);

        RiwayatRelokasi::create([
            'layanan_internet_id' => $layanan->id,
            'permohonan_layanan_id' => $permohonan->id,
            'alamat_lama' => $layanan->alamat_pemasangan,
            'latitude_lama' => $layanan->latitude,
            'longitude_lama' => $layanan->longitude,
            'alamat_baru' => $alamatBaru['alamat_pemasangan'],
            'latitude_baru' => $alamatBaru['latitude'],
            'longitude_baru' => $alamatBaru['longitude'],
            'tanggal_relokasi' => $tanggalRelokasi->toDateString(),
        ]);

        $permohonan->update([
            'alamat_pemasangan' => $alamatBaru['alamat_pemasangan'],
            'detail_alamat' => $alamatBaru['detail_alamat'],
            'latitude' => $alamatBaru['latitude'],
            'longitude' => $alamatBaru['longitude'],
        ]);

        $layanan->update([
            'alamat_pemasangan' => $alamatBaru['alamat_pemasangan'],
            'detail_alamat' => $alamatBaru['detail_alamat'],
            'latitude' => $alamatBaru['latitude'],
            'longitude' => $alamatBaru['longitude'],
        ]);
    }

    // ------------------------------------------------------------------
    // Phase 7 — Notifikasi in-app unread untuk badge merah
    // ------------------------------------------------------------------
    private function seedNotifikasi(): void
    {
        $laporan = LaporanKendala::where('status', StatusLaporanEnum::MENUNGGU)->get();
        $pembayaran = Pembayaran::where('status', StatusTransaksiEnum::BERHASIL)->get();
        $permohonan = PermohonanLayanan::where('status', StatusPermohonanEnum::MENUNGGU_VERIFIKASI)->get();

        // 1. Pendaftar baru → Operasional + Super Admin.
        foreach ($permohonan->filter(fn ($p) => $p->status === StatusPermohonanEnum::MENUNGGU_VERIFIKASI) as $p) {
            Notification::send(
                Admin::where('status_aktif', true)
                    ->whereIn('peran', [PeranAdminEnum::OPERASIONAL, PeranAdminEnum::SUPER_ADMIN])
                    ->get(),
                new PendaftarBaruNotification($p)
            );
        }

        // 2. Laporan kendala baru → Operasional + Super Admin.
        foreach ($laporan as $l) {
            Notification::send(
                Admin::where('status_aktif', true)
                    ->whereIn('peran', [PeranAdminEnum::OPERASIONAL, PeranAdminEnum::SUPER_ADMIN])
                    ->get(),
                new LaporanKendalaBaruNotification($l)
            );
        }

        // 3. Pembayaran diterima → Keuangan + Super Admin.
        foreach ($pembayaran->take(2) as $p) {
            $tagihan = $p->tagihan;
            Notification::send(
                Admin::where('status_aktif', true)
                    ->whereIn('peran', [PeranAdminEnum::KEUANGAN, PeranAdminEnum::SUPER_ADMIN])
                    ->get(),
                new PembayaranTagihanNotification($tagihan, $p)
            );
        }

        // Backdate created_at agar badge unread terlihat wajar (bukan "baru saja" semua).
        $unread = \DB::table('notifications')
            ->where('notifiable_id', $this->adminUtama->id)
            ->whereNull('read_at')
            ->whereBetween('created_at', [now()->subMinutes(10), now()])
            ->get();

        foreach ($unread as $i => $row) {
            \DB::table('notifications')->where('id', $row->id)
                ->update(['created_at' => now()->subHours(($i + 1) * 8)->subMinutes(30)]);
        }
    }

    // ------------------------------------------------------------------
    // Util
    // ------------------------------------------------------------------
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
