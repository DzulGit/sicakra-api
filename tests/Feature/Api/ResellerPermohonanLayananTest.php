<?php

namespace Tests\Feature\Api;

use App\Enums\JenisPermohonanEnum;
use App\Enums\StatusPermohonanEnum;
use App\Enums\TipePaketEnum;
use App\Models\Admin;
use App\Models\JadwalKerja;
use App\Models\LayananInternet;
use App\Models\PaketInternet;
use App\Models\Pelanggan;
use App\Models\PermohonanLayanan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ResellerPermohonanLayananTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function buatReseller(): Admin
    {
        $reseller = Admin::factory()->reseller()->create();

        return $reseller;
    }

    private function tokenAdmin(Admin $admin): string
    {
        return $admin->createToken('test')->plainTextToken;
    }

    public function test_reseller_hanya_melihat_permohonan_pelanggan_miliknya(): void
    {
        $reseller = $this->buatReseller();
        $pelangganSendiri = Pelanggan::factory()->create(['reseller_id' => $reseller->id]);
        PermohonanLayanan::factory()->create(['pelanggan_id' => $pelangganSendiri->id]);

        // Milik reseller lain & pelanggan internal — tak boleh muncul.
        $resellerLain = $this->buatReseller();
        PermohonanLayanan::factory()->create(['pelanggan_id' => Pelanggan::factory()->create(['reseller_id' => $resellerLain->id])->id]);
        PermohonanLayanan::factory()->create(['pelanggan_id' => Pelanggan::factory()->create(['reseller_id' => null])->id]);

        $this->withHeader('Authorization', "Bearer {$this->tokenAdmin($reseller)}")
            ->getJson('/api/reseller/permohonan-layanan')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.pelanggan.id', $pelangganSendiri->id);

        // Filter jenis_permohonan jalan seperti operasional.
        PermohonanLayanan::factory()->create([
            'pelanggan_id' => $pelangganSendiri->id,
            'jenis_permohonan' => JenisPermohonanEnum::RELOKASI,
        ]);

        $this->withHeader('Authorization', "Bearer {$this->tokenAdmin($reseller)}")
            ->getJson('/api/reseller/permohonan-layanan?jenis_permohonan=relokasi')
            ->assertOk()
            ->assertJsonCount(1, 'data.data');
    }

    public function test_detail_permohonan_orang_lain_atau_internal_tidak_bocor(): void
    {
        $reseller = $this->buatReseller();
        $punya = PermohonanLayanan::factory()->create([
            'pelanggan_id' => Pelanggan::factory()->create(['reseller_id' => $reseller->id])->id,
        ]);
        $milikLain = PermohonanLayanan::factory()->create([
            'pelanggan_id' => Pelanggan::factory()->create(['reseller_id' => $this->buatReseller()->id])->id,
        ]);
        $internal = PermohonanLayanan::factory()->create([
            'pelanggan_id' => Pelanggan::factory()->create(['reseller_id' => null])->id,
        ]);

        $token = "Bearer {$this->tokenAdmin($reseller)}";
        $this->withHeader('Authorization', $token)->getJson("/api/reseller/permohonan-layanan/{$punya->id}")->assertOk();
        $this->withHeader('Authorization', $token)->getJson("/api/reseller/permohonan-layanan/{$milikLain->id}")->assertNotFound();
        $this->withHeader('Authorization', $token)->getJson("/api/reseller/permohonan-layanan/{$internal->id}")->assertNotFound();
    }

    public function test_reseller_membuat_permohonan_relokasi_mewarisi_paket_layanan_lama(): void
    {
        $reseller = $this->buatReseller();
        $pelanggan = Pelanggan::factory()->create(['reseller_id' => $reseller->id]);
        $paket = PaketInternet::factory()->create(['reseller_id' => $reseller->id]);
        $layanan = LayananInternet::factory()->create([
            'pelanggan_id' => $pelanggan->id,
            'paket_internet_id' => $paket->id,
            'tipe_paket' => TipePaketEnum::REGULER,
        ]);

        $this->withHeader('Authorization', "Bearer {$this->tokenAdmin($reseller)}")
            ->postJson('/api/reseller/permohonan-layanan', [
                'pelanggan_id' => $pelanggan->id,
                'jenis_permohonan' => 'relokasi',
                'layanan_internet_id' => $layanan->id,
                'alamat_pemasangan' => 'Jl. Rumah Baru No. 9',
                'latitude' => -6.25,
                'longitude' => 106.9,
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', StatusPermohonanEnum::MENUNGGU_VERIFIKASI->value);

        $this->assertDatabaseHas('permohonan_layanan', [
            'pelanggan_id' => $pelanggan->id,
            'jenis_permohonan' => JenisPermohonanEnum::RELOKASI->value,
            'paket_internet_id' => $paket->id,
            'alamat_pemasangan' => 'Jl. Rumah Baru No. 9',
        ]);

        // Pelanggan milik reseller lain → 404 (tidak bocor).
        $pelangganLain = Pelanggan::factory()->create(['reseller_id' => $this->buatReseller()->id]);
        $this->withHeader('Authorization', "Bearer {$this->tokenAdmin($reseller)}")
            ->postJson('/api/reseller/permohonan-layanan', [
                'pelanggan_id' => $pelangganLain->id,
                'jenis_permohonan' => 'relokasi',
                'layanan_internet_id' => $layanan->id,
            ])
            ->assertNotFound();
    }

    public function test_reseller_membuat_permohonan_ganti_paket_menyimpan_paket_baru(): void
    {
        $reseller = $this->buatReseller();
        $pelanggan = Pelanggan::factory()->create(['reseller_id' => $reseller->id]);
        $paketLama = PaketInternet::factory()->create(['reseller_id' => $reseller->id]);
        $paketBaru = PaketInternet::factory()->create(['reseller_id' => $reseller->id]);
        $layanan = LayananInternet::factory()->create([
            'pelanggan_id' => $pelanggan->id,
            'paket_internet_id' => $paketLama->id,
            'tipe_paket' => TipePaketEnum::REGULER,
        ]);

        $this->withHeader('Authorization', "Bearer {$this->tokenAdmin($reseller)}")
            ->postJson('/api/reseller/permohonan-layanan', [
                'pelanggan_id' => $pelanggan->id,
                'jenis_permohonan' => 'ganti_paket',
                'layanan_internet_id' => $layanan->id,
                'paket_internet_id' => $paketBaru->id,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('permohonan_layanan', [
            'jenis_permohonan' => JenisPermohonanEnum::GANTI_PAKET->value,
            'pelanggan_id' => $pelanggan->id,
            'paket_internet_id' => $paketLama->id,
            'paket_internet_id_baru' => $paketBaru->id,
        ]);
    }

    public function test_reseller_verifikasi_menolak_permohonan_menyimpan_alasan(): void
    {
        $reseller = $this->buatReseller();
        $pelanggan = Pelanggan::factory()->create(['reseller_id' => $reseller->id]);
        $permohonan = PermohonanLayanan::factory()->create(['pelanggan_id' => $pelanggan->id]);

        $this->withHeader('Authorization', "Bearer {$this->tokenAdmin($reseller)}")
            ->patchJson("/api/reseller/permohonan-layanan/{$permohonan->id}/verifikasi", [
                'status' => 'DITOLAK',
                'catatan' => 'Dokumen kurang lengkap',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', StatusPermohonanEnum::DITOLAK->value);

        $this->assertDatabaseHas('permohonan_layanan', [
            'id' => $permohonan->id,
            'status' => StatusPermohonanEnum::DITOLAK->value,
            'alasan_ditolak' => 'Dokumen kurang lengkap',
        ]);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $pelanggan->id]);
    }

    public function test_reseller_verifikasi_dan_jadwalkan_tanpa_teknisi(): void
    {
        $reseller = $this->buatReseller();
        $pelanggan = Pelanggan::factory()->create(['reseller_id' => $reseller->id]);
        $permohonan = PermohonanLayanan::factory()->create(['pelanggan_id' => $pelanggan->id]);

        $this->withHeader('Authorization', "Bearer {$this->tokenAdmin($reseller)}")
            ->postJson("/api/reseller/permohonan-layanan/{$permohonan->id}/verifikasi-dan-jadwalkan", [
                'status' => 'DITERIMA',
                'tanggal_kerja' => now()->addDays(2)->toDateString(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.permohonan.status', StatusPermohonanEnum::DIJADWALKAN->value);

        $this->assertDatabaseHas('jadwal_kerja', [
            'permohonan_layanan_id' => $permohonan->id,
            'tanggal_kerja' => now()->addDays(2)->format('Y-m-d 00:00:00'),
        ]);
        $this->assertDatabaseHas('permohonan_layanan', [
            'id' => $permohonan->id,
            'status' => StatusPermohonanEnum::DIJADWALKAN->value,
        ]);
    }

    public function test_reseller_jadwalkan_ulang_setelah_ditunda(): void
    {
        $reseller = $this->buatReseller();
        $pelanggan = Pelanggan::factory()->create(['reseller_id' => $reseller->id]);
        $permohonan = PermohonanLayanan::factory()->create([
            'pelanggan_id' => $pelanggan->id,
            'status' => StatusPermohonanEnum::DITUNDA,
        ]);

        $this->withHeader('Authorization', "Bearer {$this->tokenAdmin($reseller)}")
            ->postJson("/api/reseller/permohonan-layanan/{$permohonan->id}/jadwalkan-kerja", [
                'tanggal_kerja' => now()->addDays(3)->toDateString(),
            ])
            ->assertCreated();

        $this->assertDatabaseHas('jadwal_kerja', [
            'permohonan_layanan_id' => $permohonan->id,
            'tanggal_kerja' => now()->addDays(3)->format('Y-m-d 00:00:00'),
        ]);
        $this->assertDatabaseHas('permohonan_layanan', [
            'id' => $permohonan->id,
            'status' => StatusPermohonanEnum::DIJADWALKAN->value,
        ]);
        $this->assertSame(0, JadwalKerja::where('permohonan_layanan_id', $permohonan->id)->first()->teknisi()->count());
    }
}
