<?php

namespace Tests\Feature\Api\Keuangan;

use App\Models\Admin;
use App\Models\Pelanggan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AturTanggalTagihanTest extends TestCase
{
    use RefreshDatabase;

    public function test_keuangan_atur_tanggal_tagihan_per_pelanggan(): void
    {
        $admin = Admin::factory()->keuangan()->create();
        $pelanggan = Pelanggan::factory()->create(['tanggal_tagihan' => 20]);

        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/operasional/pelanggan/{$pelanggan->id}/tanggal-tagihan", [
            'tanggal_tagihan' => 15,
        ])->assertOk()->assertJsonPath('data.tanggal_tagihan', 15);

        $this->assertEquals(15, $pelanggan->fresh()->tanggal_tagihan);
    }

    public function test_keuangan_bulk_atur_tanggal_tagihan_semua_pelanggan_aktif(): void
    {
        $admin = Admin::factory()->keuangan()->create();
        Pelanggan::factory()->create(['nomor_pelanggan' => 'PLG-001', 'tanggal_tagihan' => 10]);
        Pelanggan::factory()->create(['nomor_pelanggan' => 'PLG-002', 'tanggal_tagihan' => 10]);
        // Belum aktif (tanpa nomor_pelanggan) — tidak ikut di-update.
        Pelanggan::factory()->create(['nomor_pelanggan' => null, 'tanggal_tagihan' => 10]);

        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/operasional/pelanggan/tanggal-tagihan/bulk', [
            'tanggal_tagihan' => 25,
        ])->assertOk()->assertJsonPath('data.ter_update', 2);

        $this->assertEquals(2, Pelanggan::where('tanggal_tagihan', 25)->count());
    }

    public function test_bulk_validasi_tanggal_di_luar_range(): void
    {
        $admin = Admin::factory()->keuangan()->create();

        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/operasional/pelanggan/tanggal-tagihan/bulk', [
            'tanggal_tagihan' => 32,
        ])->assertStatus(422);
    }
}
