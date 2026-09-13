<?php

namespace Tests\Feature\Api\Operasional;

use App\Enums\StatusLayananEnum;
use App\Models\Admin;
use App\Models\LayananInternet;
use App\Models\Pelanggan;
use App\Models\Tagihan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PelangganShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_detail_pelanggan_tidak_error_dari_tagihan_status_lama_kedaluwarsa(): void
    {
        $admin = Admin::factory()->operasional()->create();
        Sanctum::actingAs($admin);

        $pelanggan = Pelanggan::factory()->create();
        $layanan = LayananInternet::factory()->create([
            'pelanggan_id' => $pelanggan->id,
            'status' => StatusLayananEnum::AKTIF,
        ]);

        $tagihan = Tagihan::factory()->create([
            'layanan_internet_id' => $layanan->id,
            'status_pembayaran' => 'belum_bayar',
        ]);

        // Sisa data peninggalan status 'kedaluwarsa' yang sudah dihapus dari enum.
        DB::table('tagihan')
            ->where('id', $tagihan->id)
            ->update(['status_pembayaran' => 'kedaluwarsa']);

        // Migrasi data: kedaluwarsa -> belum_bayar
        $migrasi = require base_path(
            'database/migrations/2026_09_13_150000_convert_kedaluwarsa_tagihan_ke_belum_bayar.php'
        );
        $migrasi->up();

        $this->assertSame(
            'belum_bayar',
            DB::table('tagihan')->where('id', $tagihan->id)->value('status_pembayaran')
        );

        $this->getJson("/api/admin/operasional/pelanggan/{$pelanggan->id}")
            ->assertOk();
    }
}