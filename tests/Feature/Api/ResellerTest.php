<?php

namespace Tests\Feature\Api;

use App\Enums\PeranAdminEnum;
use App\Enums\StatusLayananEnum;
use App\Models\Admin;
use App\Models\PaketInternet;
use App\Models\Pelanggan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ResellerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_operasional_dapat_crud_reseller_dan_keuangan_diblokir(): void
    {
        $operasional = Admin::factory()->operasional()->create();
        $token = $operasional->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/admin/operasional/reseller', [
                'nama_lengkap' => 'Reseller Baru',
                'email' => 'reseller.baru@example.com',
                'password' => 'password123',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.peran', PeranAdminEnum::RESELLER->value);

        $reseller = Admin::where('email', 'reseller.baru@example.com')->first();
        $this->assertEquals(PeranAdminEnum::RESELLER, $reseller->peran);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/operasional/reseller')
            ->assertOk()
            ->assertJsonCount(1, 'data.data');
    }

    public function test_keuangan_tidak_bisa_memantau_pelanggan_reseller(): void
    {
        $keuangan = Admin::factory()->keuangan()->create();
        $reseller = Admin::factory()->reseller()->create();
        $token = $keuangan->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/admin/operasional/reseller/{$reseller->id}/pelanggan")
            ->assertForbidden();
    }

    public function test_reseller_mendaftarkan_pelanggan_langsung_aktif_and_foto_webp(): void
    {
        $reseller = Admin::factory()->reseller()->create();
        $paket = PaketInternet::factory()->create(['reseller_id' => $reseller->id]);
        $token = $reseller->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/reseller/pelanggan', [
                'nama_lengkap' => 'Andi Test',
                'nik' => '1234567890123456',
                'nomor_hp' => '081234567890',
                'email' => 'andi.test@example.com',
                'alamat_pemasangan' => 'Jl. Test No. 1',
                'tipe_paket' => 'reguler',
                'paket_internet_id' => $paket->id,
                'foto_ktp' => UploadedFile::fake()->image('ktp.jpg'),
            ])
            ->assertCreated();

        // Bypass alur permohonan/verifikasi: langsung aktif + tak ada notifikasi.
        $pelanggan = Pelanggan::where('email', 'andi.test@example.com')->first();
        $this->assertNotNull($pelanggan);
        $this->assertSame($reseller->id, $pelanggan->reseller_id);
        $this->assertNotNull($pelanggan->foto_ktp);
        $this->assertTrue(str_ends_with($pelanggan->foto_ktp, '.webp'));

        $layanan = $pelanggan->layananInternet->first();
        $this->assertNotNull($layanan);
        $this->assertEquals(StatusLayananEnum::AKTIF, $layanan->status);
        $this->assertNull($layanan->permohonan_layanan_id);
    }
}
