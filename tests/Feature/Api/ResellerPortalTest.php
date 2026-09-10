<?php

namespace Tests\Feature\Api;

use App\Enums\PeranAdminEnum;
use App\Enums\StatusLayananEnum;
use App\Models\Admin;
use App\Models\LayananInternet;
use App\Models\PaketInternet;
use App\Models\Pelanggan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ResellerPortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_login_portal_hanya_untuk_reseller_dan_login_admin_menolak_reseller(): void
    {
        $reseller = Admin::factory()->reseller()->create(['password' => 'password123']);
        $operasional = Admin::factory()->operasional()->create(['password' => 'password123']);

        // Reseller di portal reseller -> 200
        $this->postJson('/api/reseller/login', [
            'email' => $reseller->email,
            'password' => 'password123',
        ])->assertOk()->assertJsonPath('data.admin.peran', PeranAdminEnum::RESELLER->value);

        // Reseller mencoba masuk portal admin internal -> ditolak
        $this->postJson('/api/admin/login', [
            'email' => $reseller->email,
            'password' => 'password123',
        ])->assertStatus(422);

        // Admin internal mencoba masuk portal reseller -> ditolak
        $this->postJson('/api/reseller/login', [
            'email' => $operasional->email,
            'password' => 'password123',
        ])->assertStatus(422);

        // Admin internal tetap bisa login di portal admin
        $this->postJson('/api/admin/login', [
            'email' => $operasional->email,
            'password' => 'password123',
        ])->assertOk();
    }

    public function test_reseller_tidak_bisa_akses_endpoint_admin_internal(): void
    {
        $reseller = Admin::factory()->reseller()->create();
        $token = $reseller->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/operasional/dashboard')
            ->assertForbidden();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/operasional/pelanggan')
            ->assertForbidden();
    }

    public function test_dashboard_reseller_menampilkan_data_pelanggan_miliknya_saja(): void
    {
        $reseller = Admin::factory()->reseller()->create();
        $token = $reseller->createToken('test')->plainTextToken;

        $paket = PaketInternet::factory()->create();
        $pelangganReseller = Pelanggan::factory()->create(['reseller_id' => $reseller->id]);
        $pelangganInternal = Pelanggan::factory()->create(['reseller_id' => null]);

        LayananInternet::factory()->create([
            'pelanggan_id' => $pelangganReseller->id,
            'paket_internet_id' => $paket->id,
            'status' => 'aktif',
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/reseller/dashboard')
            ->assertOk()
            ->assertJsonPath('data.stats.total_pelanggan', 1)
            ->assertJsonPath('data.stats.pelanggan_aktif', 1)
            ->assertJsonCount(1, 'data.pelanggan_terbaru');
    }

    public function test_reseller_hanya_melihat_pelanggan_miliknya_dan_detail_milik_orang_404(): void
    {
        $reseller = Admin::factory()->reseller()->create();
        $token = $reseller->createToken('test')->plainTextToken;

        $punya = Pelanggan::factory()->create(['reseller_id' => $reseller->id]);
        $punyaResellerLain = Pelanggan::factory()->create(['reseller_id' => Admin::factory()->reseller()->create()->id]);
        $internal = Pelanggan::factory()->create(['reseller_id' => null]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/reseller/pelanggan')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $punya->id);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/reseller/pelanggan/{$punya->id}")
            ->assertOk();

        // Detail pelanggan milik reseller lain & pelanggan internal -> 404 (tidak bocor)
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/reseller/pelanggan/{$punyaResellerLain->id}")
            ->assertNotFound();
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/reseller/pelanggan/{$internal->id}")
            ->assertNotFound();
    }

    public function test_reseller_mendaftarkan_pelanggan_tercataat_sebagai_miliknya(): void
    {
        $reseller = Admin::factory()->reseller()->create();
        $paket = PaketInternet::factory()->create(['reseller_id' => $reseller->id]);
        $token = $reseller->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/reseller/pelanggan', [
                'nama_lengkap' => 'Andi Portal',
                'nik' => '1234567890123456',
                'nomor_hp' => '081234567890',
                'email' => 'andi.portal@example.com',
                'alamat_pemasangan' => 'Jl. Portal No. 1',
                'tipe_paket' => 'reguler',
                'paket_internet_id' => $paket->id,
                'foto_ktp' => UploadedFile::fake()->image('ktp.jpg'),
            ])
            ->assertCreated();

        // Reseller bypass permohonan/verifikasi: pelanggan langsung AKTIF.
        $pelanggan = Pelanggan::where('email', 'andi.portal@example.com')->first();
        $this->assertNotNull($pelanggan);
        $this->assertSame($reseller->id, $pelanggan->reseller_id);
        $this->assertStringStartsWith('RSL', $pelanggan->nomor_pelanggan);

        // Foto yang diunggah harus otomatis terkompresi jadi WebP.
        $this->assertNotNull($pelanggan->foto_ktp);
        $this->assertTrue(str_ends_with($pelanggan->foto_ktp, '.webp'));

        $layanan = $pelanggan->layananInternet->first();
        $this->assertNotNull($layanan);
        $this->assertSame($paket->id, $layanan->paket_internet_id);
        $this->assertEquals(StatusLayananEnum::AKTIF, $layanan->status);
        $this->assertNull($layanan->permohonan_layanan_id);
    }
}
