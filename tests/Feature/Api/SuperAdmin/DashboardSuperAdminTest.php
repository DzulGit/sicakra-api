<?php

namespace Tests\Feature\Api\SuperAdmin;

use App\Enums\PeranAdminEnum;
use App\Enums\StatusTransaksiEnum;
use App\Models\Admin;
use App\Models\LaporanKendala;
use App\Models\LayananInternet;
use App\Models\Pelanggan;
use App\Models\Pembayaran;
use App\Models\TimTeknisi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardSuperAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_super_admin_mengembalikan_ringkasan(): void
    {
        $superAdmin = Admin::factory()->superAdmin()->create();
        Sanctum::actingAs($superAdmin);

        $pelanggan = Pelanggan::factory()->create(['created_at' => now()->subMonths(2)]);
        LayananInternet::factory()->create([
            'pelanggan_id' => $pelanggan->id,
            'status' => 'aktif',
        ]);

        Pembayaran::factory()->create([
            'pelanggan_id' => $pelanggan->id,
            'status' => StatusTransaksiEnum::BERHASIL,
            'jumlah_dibayar' => 250000,
            'dibayar_pada' => now(),
        ]);

        TimTeknisi::create(['nama_tim' => 'Tim Angin', 'status_aktif' => true]);

        $response = $this->getJson('/api/admin/super-admin/dashboard');

        $response->assertOk()
            ->assertJsonPath('data.stats.total_pelanggan', 1)
            ->assertJsonPath('data.stats.pelanggan_aktif', 1)
            ->assertJsonPath('data.stats.jumlah_tim_teknisi', 1);

        $response->assertJsonStructure([
            'data' => [
                'stats' => ['total_pelanggan', 'pelanggan_aktif', 'pertumbuhan_pelanggan', 'total_teknisi', 'jumlah_tim_teknisi', 'pendapatan_bulan_ini', 'kendala_aktif'],
                'tren_pelanggan' => [['bulan', 'jumlah']],
                'tren_pendapatan' => [['bulan', 'jumlah']],
                'aktivitas_terbaru' => [['id', 'tipe', 'deskripsi', 'pengguna', 'waktu']],
                'kesehatan_sistem',
            ],
        ]);
    }

    public function test_dashboard_super_admin_tidak_bisa_diakses_peran_lain(): void
    {
        $admin = Admin::factory()->keuangan()->create();
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/super-admin/dashboard')
            ->assertForbidden();
    }
}