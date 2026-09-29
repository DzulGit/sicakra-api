<?php

namespace Tests\Feature\Api\Operasional;

use App\Enums\StatusPermohonanEnum;
use App\Models\Admin;
use App\Models\PermohonanLayanan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PermohonanLayananListTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = Admin::factory()->operasional()->create()->createToken('test')->plainTextToken;
    }

    public function test_daftar_diurutkan_per_tahap_antrean_kerja(): void
    {
        // Diberi created_at TERBALIK dari urutan status supaya kebetulan "terbaru
        // dulu" tidak bisa cocok — urutan wajib ditentukan oleh tahap status.
        PermohonanLayanan::factory()->create([
            'status' => StatusPermohonanEnum::DIKONVERSI,
            'created_at' => now()->subDay(),
        ]);
        PermohonanLayanan::factory()->create([
            'status' => StatusPermohonanEnum::DITERIMA,
            'created_at' => now(),
        ]);
        PermohonanLayanan::factory()->create([
            'status' => StatusPermohonanEnum::MENUNGGU_VERIFIKASI,
            'created_at' => now()->subDays(2),
        ]);
        PermohonanLayanan::factory()->create([
            'status' => StatusPermohonanEnum::DIJADWALKAN,
            'created_at' => now()->subDays(3),
        ]);

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/admin/operasional/permohonan-layanan')
            ->assertOk()
            ->assertJsonPath('data.total', 4)
            ->assertJsonPath('data.data.0.status', StatusPermohonanEnum::MENUNGGU_VERIFIKASI->value)
            ->assertJsonPath('data.data.1.status', StatusPermohonanEnum::DITERIMA->value)
            ->assertJsonPath('data.data.2.status', StatusPermohonanEnum::DIJADWALKAN->value)
            ->assertJsonPath('data.data.3.status', StatusPermohonanEnum::DIKONVERSI->value);
    }

    public function test_dalam_satu_tahap_yang_terbaru_didulu(): void
    {
        $lama = PermohonanLayanan::factory()->create([
            'status' => StatusPermohonanEnum::MENUNGGU_VERIFIKASI,
            'created_at' => now()->subDays(3),
        ]);
        $baru = PermohonanLayanan::factory()->create([
            'status' => StatusPermohonanEnum::MENUNGGU_VERIFIKASI,
            'created_at' => now(),
        ]);

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/admin/operasional/permohonan-layanan')
            ->assertOk()
            ->assertJsonPath('data.data.0.id', $baru->id)
            ->assertJsonPath('data.data.1.id', $lama->id);
    }

    public function test_urutan_tetap_sesudah_filter_dipakai(): void
    {
        PermohonanLayanan::factory()->create([
            'status' => StatusPermohonanEnum::DIKONVERSI,
            'created_at' => now()->subDay(),
        ]);
        PermohonanLayanan::factory()->create([
            'status' => StatusPermohonanEnum::DITERIMA,
            'created_at' => now(),
        ]);
        PermohonanLayanan::factory()->create([
            'status' => StatusPermohonanEnum::MENUNGGU_VERIFIKASI,
            'created_at' => now()->subDays(2),
        ]);

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/admin/operasional/permohonan-layanan?status='.StatusPermohonanEnum::DITERIMA->value.','.StatusPermohonanEnum::DIKONVERSI->value)
            ->assertOk()
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.data.0.status', StatusPermohonanEnum::DITERIMA->value)
            ->assertJsonPath('data.data.1.status', StatusPermohonanEnum::DIKONVERSI->value);
    }

}
