<?php

namespace Database\Seeders;

use App\Models\PaketInternet;
use Illuminate\Database\Seeder;

class PaketInternetSeeder extends Seeder
{
    public function run(): void
    {
        $paket = [
            [
                'nama_paket' => 'Paket Bronze',
                'kecepatan_mbps' => 20,
                'harga' => 150000,
                'jumlah_perangkat' => 5,
                'deskripsi' => 'Internet stabil untuk kebutuhan dasar dan browsing ringan.',
                'status_aktif' => true,
            ],
            [
                'nama_paket' => 'Paket Silver',
                'kecepatan_mbps' => 50,
                'harga' => 250000,
                'jumlah_perangkat' => 10,
                'deskripsi' => 'Cocok untuk streaming HD dan kebutuhan keluarga kecil.',
                'status_aktif' => true,
            ],
            [
                'nama_paket' => 'Paket Gold',
                'kecepatan_mbps' => 100,
                'harga' => 400000,
                'jumlah_perangkat' => 20,
                'deskripsi' => 'Kecepatan tinggi untuk gaming, streaming 4K, dan banyak perangkat.',
                'status_aktif' => true,
            ],
            [
                'nama_paket' => 'Paket Platinum',
                'kecepatan_mbps' => 200,
                'harga' => 600000,
                'jumlah_perangkat' => 30,
                'deskripsi' => 'Pilihan terbaik untuk rumah besar atau kantor kecil dengan banyak perangkat.',
                'status_aktif' => true,
            ],
            [
                'nama_paket' => 'Paket Diamond',
                'kecepatan_mbps' => 500,
                'harga' => 1000000,
                'jumlah_perangkat' => 50,
                'deskripsi' => 'Kecepatan ultra tinggi untuk streaming, gaming, dan banyak perangkat secara bersamaan.',
                'status_aktif' => true,
            ],
            [
                'nama_paket' => 'Paket Ultimate',
                'kecepatan_mbps' => 1000,
                'harga' => 1500000,
                'jumlah_perangkat' => 100,
                'deskripsi' => 'Paket premium untuk kebutuhan internet tanpa batas dan performa terbaik.',
                'status_aktif' => true,
            ],
            [
                'nama_paket' => 'Paket Enterprise',
                'kecepatan_mbps' => 2000,
                'harga' => 2500000,
                'jumlah_perangkat' => 200,
                'deskripsi' => 'Solusi internet untuk perusahaan besar dengan banyak karyawan dan perangkat.',
                'status_aktif' => true,
            ],
            [
                'nama_paket' => 'Paket Ultra',
                'kecepatan_mbps' => 5000,
                'harga' => 5000000,
                'jumlah_perangkat' => 500,
                'deskripsi' => 'Paket internet tercepat untuk kebutuhan bisnis dan hiburan tanpa kompromi.',
                'status_aktif' => true,
            ],
            [
                'nama_paket' => 'Paket Mega',
                'kecepatan_mbps' => 10000,
                'harga' => 10000000,
                'jumlah_perangkat' => 1000,
                'deskripsi' => 'Paket internet super cepat untuk perusahaan besar dan pengguna yang membutuhkan bandwidth tinggi.',
                'status_aktif' => true,
            ],
            [
                'nama_paket' => 'Paket Giga',
                'kecepatan_mbps' => 20000,
                'harga' => 20000000,
                'jumlah_perangkat' => 2000,
                'deskripsi' => 'Paket internet tercepat untuk perusahaan besar dan pengguna yang membutuhkan bandwidth tinggi.',
                'status_aktif' => true,
            ],
        ];

        foreach ($paket as $data) {
            PaketInternet::create($data);
        }
    }
}