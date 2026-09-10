<?php

namespace Database\Seeders;

use App\Enums\PeranAdminEnum;
use App\Models\Admin;
use App\Models\PaketInternet;
use Illuminate\Database\Seeder;

/**
 * ResellerPaketInternetSeeder — seed paket internet yang dimiliki oleh reseller.
 *
 * Berbeda dengan PaketInternetSeeder (paket umum untuk semua pelanggan,
 * reseller_id = NULL), seeder ini menciptakan paket-paket EksKLUSIF
 * yang hanya tersedia di bawah akun reseller tertentu.
 *
 * Paket reseller dipilih berdasarkan reseller_id; controller Reseller
 * hanya menampilkan paket dengan reseller_id = user yang login.
 */
class ResellerPaketInternetSeeder extends Seeder
{
    public function run(): void
    {
        // Akun reseller utama demo (dibuat oleh DemoSeeder / bisa manual).
        $reseller = Admin::where('peran', PeranAdminEnum::RESELLER)
            ->where('email', 'reseller@sicakra.com')
            ->first();

        if (! $reseller) {
            // Fallback: cari reseller pertama. Jika tidak ada, lewati agar tidak FK error.
            $reseller = Admin::where('peran', PeranAdminEnum::RESELLER)->first();
        }

        if (! $reseller) {
            $this->command->warn('Tidak ada akun reseller ditemukan — ResellerPaketInternetSeeder dilewati.');
            return;
        }

        $this->command->info("Seed paket reseller untuk: {$reseller->nama_lengkap} (ID: {$reseller->id})");

        $paket = [
            // ---- Reseller: paket branded eksklusif ----
            [
                'nama_paket'    => 'Reseller Nusantara 10 Mbps',
                'kecepatan_mbps' => 10,
                'harga'        => 120000,
                'jumlah_perangkat' => 3,
                'deskripsi'    => 'Paket WiFi dasar khusus reseller. Sumber daya jaringan prioritas rendah.',
                'status_aktif' => true,
            ],
            [
                'nama_paket'    => 'Reseller Nusantara 30 Mbps',
                'kecepatan_mbps' => 30,
                'harga'        => 220000,
                'jumlah_perangkat' => 8,
                'deskripsi'    => 'Paket menengah khusus reseller. Cocok untuk RT/RW atau kost-kostan.',
                'status_aktif' => true,
            ],
            [
                'nama_paket'    => 'Reseller Nusantara 100 Mbps',
                'kecepatan_mbps' => 100,
                'harga'        => 450000,
                'jumlah_perangkat' => 20,
                'deskripsi'    => 'Paket premium khusus reseller. Unlimited hingga kuota fair usage terpenuhi.',
                'status_aktif' => true,
            ],
            [
                'nama_paket'    => 'Reseller Nusantara 200 Mbps',
                'kecepatan_mbps' => 200,
                'harga'        => 800000,
                'jumlah_perangkat' => 40,
                'deskripsi'    => 'Paket enterprise khusus reseller untuk usaha menengah.',
                'status_aktif' => true,
            ],

            // ---- Reseller: paket promo ----
            [
                'nama_paket'    => 'Reseller Promo 50 Mbps 1 Bulan',
                'kecepatan_mbps' => 50,
                'harga'        => 180000,
                'jumlah_perangkat' => 10,
                'deskripsi'    => 'Cocok untuk keluarga — 50 Mbps, bulan pertama GRATIS untuk pelanggan baru reseller.',
                'status_aktif' => true,
                'promo_gratis_bulan' => 1,
            ],
            [
                'nama_paket'    => 'Reseller Promo 100 Mbps 3 Bulan',
                'kecepatan_mbps' => 100,
                'harga'        => 400000,
                'jumlah_perangkat' => 15,
                'deskripsi'    => 'Paket cepat dengan 3 bulan gratis. Diskon khusus pelanggan baru reseller.',
                'status_aktif' => true,
                'promo_gratis_bulan' => 3,
            ],
        ];

        foreach ($paket as $data) {
            PaketInternet::updateOrCreate(
                ['nama_paket' => $data['nama_paket']],
                array_merge($data, [
                    'reseller_id' => $reseller->id,
                    'promo_gratis_bulan' => $data['promo_gratis_bulan'] ?? 0,
                ]),
            );
        }

        $count = count($paket);
        $this->command->info("{$count} paket reseller berhasil diseed.");
    }
}
