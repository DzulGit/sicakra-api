<?php

namespace Tests\Feature\Api\Operasional;

use App\Models\Admin;
use App\Models\Pelanggan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PelangganListCariCaseInsensitiveTest extends TestCase
{
    use RefreshDatabase;

    private Admin $operasional;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->operasional = Admin::factory()->operasional()->create();
        $this->token = $this->operasional->createToken('test')->plainTextToken;
    }

    public function test_cari_nama_tidak_peka_huruf_besar_kecil(): void
    {
        Pelanggan::factory()->create(['nama_lengkap' => 'Mahmud Abdullah']);

        foreach (['mahmud', 'MAHMUD', 'MahMuD'] as $kata) {
            $this->withHeader('Authorization', "Bearer {$this->token}")
                ->getJson('/api/admin/operasional/pelanggan?cari='.urlencode($kata))
                ->assertOk()
                ->assertJsonPath('data.total', 1)
                ->assertJsonPath('data.data.0.nama_lengkap', 'Mahmud Abdullah');
        }
    }

    public function test_cari_nomor_pelanggan_tidak_peka_huruf_besar_kecil(): void
    {
        $pelanggan = Pelanggan::factory()->sudahAktif()->create();
        $nomor = strtolower($pelanggan->nomor_pelanggan);

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/admin/operasional/pelanggan?cari='.urlencode($nomor))
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.id', $pelanggan->id);
    }
}