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
}
