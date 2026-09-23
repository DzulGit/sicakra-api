<?php

namespace Tests\Feature\Security;

use App\Models\Admin;
use App\Models\PaketInternet;
use App\Models\Pelanggan;
use App\Services\KtpStorageService;
use App\Support\KompresiGambar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Keamanan penyimpanan foto KTP:
 *  - selalu di disk PRIVATE, isi file ter-enkripsi AES-256 (Crypt + APP_KEY),
 *  - tidak ada URL/public endpoint untuk dokumen,
 *  - preview hanya lewat endpoint ber-authorization (admin internal / reseller
 *    pemilik), path selalu dari record pelanggan (anti path traversal).
 */
class FotoKtpSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('private');
    }

    public function test_upload_via_reseller_tersimpan_di_private_disk(): void
    {
        $reseller = Admin::factory()->reseller()->create();
        $paket = PaketInternet::factory()->create(['reseller_id' => $reseller->id]);
        $token = $reseller->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/reseller/pelanggan', [
                'nama_lengkap' => 'Andi Keamanan',
                'nik' => '1234567890123456',
                'nomor_hp' => '081234567890',
                'email' => 'andi.keamanan@example.com',
                'alamat_pemasangan' => 'Jl. Aman No. 1',
                'tipe_paket' => 'reguler',
                'paket_internet_id' => $paket->id,
                'foto_ktp' => UploadedFile::fake()->image('ktp.jpg'),
            ])
            ->assertCreated();

        $pelanggan = Pelanggan::where('email', 'andi.keamanan@example.com')->firstOrFail();

        $this->assertNotNull($pelanggan->foto_ktp);
        $this->assertTrue(Storage::disk('private')->exists($pelanggan->foto_ktp));
        $this->assertFalse(Storage::disk('public')->exists($pelanggan->foto_ktp));
    }

    public function test_upload_via_pendaftaran_tersimpan_di_private_disk(): void
    {
        $paket = PaketInternet::factory()->create();

        $this->postJson('/api/pendaftaran', [
            'nama_lengkap' => 'Budi Keamanan',
            'nik' => '9999888877776666',
            'nomor_hp' => '087777777777',
            'email' => 'budi.keamanan@example.com',
            'alamat_pemasangan' => 'Jl. Merdeka No. 1',
            'rt' => '001',
            'rw' => '002',
            'kode_pos' => '50000',
            'latitude' => -6.2,
            'longitude' => 106.8,
            'tipe_paket' => 'reguler',
            'paket_internet_id' => $paket->id,
            'foto_ktp' => UploadedFile::fake()->image('ktp.jpg'),
        ])->assertCreated();

        $pelanggan = Pelanggan::where('email', 'budi.keamanan@example.com')->firstOrFail();

        $this->assertNotNull($pelanggan->foto_ktp);
        $this->assertTrue(Storage::disk('private')->exists($pelanggan->foto_ktp));
        $this->assertFalse(Storage::disk('public')->exists($pelanggan->foto_ktp));
    }

    public function test_isi_file_di_disk_terenkripsi_bukan_plaintext_dan_terdekripsi_ke_webp(): void
    {
        $pelanggan = Pelanggan::factory()->create([
            'foto_ktp' => KtpStorageService::simpan(UploadedFile::fake()->image('ktp.jpg')),
        ]);

        $isiDisk = Storage::disk('private')->get($pelanggan->foto_ktp);

        $this->assertNotNull($isiDisk);
        // Bukan plaintext: tidak diawali magic RIFF (WebP mentah).
        $this->assertFalse(str_starts_with($isiDisk, "\x52\x49\x46\x46"));
        // Hasil dekripsi adalah WebP asli.
        $this->assertStringStartsWith("\x52\x49\x46\x46", Crypt::decryptString($isiDisk));
    }

    public function test_konfigurasi_cipher_aes_256_dengan_app_key_32_byte(): void
    {
        $this->assertSame('AES-256-CBC', config('app.cipher'));

        $kunci = base64_decode(substr((string) config('app.key'), 7), true);
        $this->assertSame(32, strlen($kunci), 'APP_KEY harus 32 byte (AES-256)');
    }

    public function test_public_disk_tidak_menyimpan_dan_route_publik_lama_404(): void
    {
        $pelanggan = Pelanggan::factory()->create([
            'foto_ktp' => KtpStorageService::simpan(UploadedFile::fake()->image('ktp.jpg')),
        ]);

        $this->assertFalse(Storage::disk('public')->exists($pelanggan->foto_ktp));

        // Endpoint publik tanpa auth yang dulu ada sudah diputus.
        $this->getJson('/api/foto-ktp/'.$pelanggan->foto_ktp)->assertNotFound();
    }

    public function test_preview_admin_menolak_teknisi(): void
    {
        $pelanggan = Pelanggan::factory()->create([
            'foto_ktp' => KtpStorageService::simpan(UploadedFile::fake()->image('ktp.jpg')),
        ]);

        $teknisi = Admin::factory()->teknisi()->create();
        $token = $teknisi->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/admin/operasional/pelanggan/{$pelanggan->id}/foto-ktp")
            ->assertForbidden();
    }

    public function test_preview_admin_tanpa_token_ditolak_401(): void
    {
        $pelanggan = Pelanggan::factory()->create([
            'foto_ktp' => KtpStorageService::simpan(UploadedFile::fake()->image('ktp.jpg')),
        ]);

        $this->getJson("/api/admin/operasional/pelanggan/{$pelanggan->id}/foto-ktp")
            ->assertUnauthorized();
    }

    public function test_admin_operasional_bisa_preview_pelanggan_internal(): void
    {
        $admin = Admin::factory()->operasional()->create();
        $token = $admin->createToken('test')->plainTextToken;

        $pelanggan = Pelanggan::factory()->create([
            'foto_ktp' => KtpStorageService::simpan(UploadedFile::fake()->image('ktp.jpg')),
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/admin/operasional/pelanggan/{$pelanggan->id}/foto-ktp");

        $response->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->assertStringStartsWith("\x52\x49\x46\x46", $response->getContent());
    }

    public function test_admin_operasional_hanya_bisa_preview_pelanggan_reseller_lewat_scope_reseller(): void
    {
        $reseller = Admin::factory()->reseller()->create();
        $pelanggan = Pelanggan::factory()->create([
            'reseller_id' => $reseller->id,
            'foto_ktp' => KtpStorageService::simpan(UploadedFile::fake()->image('ktp.jpg')),
        ]);

        $admin = Admin::factory()->operasional()->create();
        $token = $admin->createToken('test')->plainTextToken;

        // Endpoint pelanggan internal tidak melayani pelanggan milik reseller.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/admin/operasional/pelanggan/{$pelanggan->id}/foto-ktp")
            ->assertNotFound();

        // Lewat scope reseller → boleh (pola ResellerPolicy::lihatPelanggan).
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/admin/operasional/reseller/{$reseller->id}/pelanggan/{$pelanggan->id}/foto-ktp")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/webp');
    }

    public function test_reseller_hanya_bisa_preview_pelanggan_miliknya_sendiri(): void
    {
        $resellerA = Admin::factory()->reseller()->create();
        $tokenA = $resellerA->createToken('test')->plainTextToken;

        $resellerB = Admin::factory()->reseller()->create();

        $punya = Pelanggan::factory()->create([
            'reseller_id' => $resellerA->id,
            'foto_ktp' => KtpStorageService::simpan(UploadedFile::fake()->image('ktp.jpg')),
        ]);
        $punyaResellerLain = Pelanggan::factory()->create([
            'reseller_id' => $resellerB->id,
            'foto_ktp' => KtpStorageService::simpan(UploadedFile::fake()->image('ktp.jpg')),
        ]);
        $internal = Pelanggan::factory()->create([
            'reseller_id' => null,
            'foto_ktp' => KtpStorageService::simpan(UploadedFile::fake()->image('ktp.jpg')),
        ]);

        $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->getJson("/api/reseller/pelanggan/{$punya->id}/foto-ktp")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/webp');

        $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->getJson("/api/reseller/pelanggan/{$punyaResellerLain->id}/foto-ktp")
            ->assertNotFound();
        $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->getJson("/api/reseller/pelanggan/{$internal->id}/foto-ktp")
            ->assertNotFound();
    }

    public function test_ganti_foto_ktp_menyimpan_file_baru_dan_menghapus_file_lama(): void
    {
        $pelanggan = Pelanggan::factory()->create(['foto_ktp' => 'ktp/lama.jpg']);

        // Legacy lama masih plaintext di disk public.
        Storage::disk('public')->put('ktp/lama.jpg', 'legacy-plaintext-bytes');

        $pathBaru = KtpStorageService::ganti($pelanggan, UploadedFile::fake()->image('ktp.jpg'));

        $this->assertSame($pathBaru, $pelanggan->fresh()->foto_ktp);
        $this->assertTrue(Storage::disk('private')->exists($pathBaru));
        $this->assertFalse(Storage::disk('public')->exists('ktp/lama.jpg'));
        $this->assertFalse(Storage::disk('private')->exists('ktp/lama.jpg'));
    }

    public function test_upload_bukan_gambar_ditolak_dan_tidak_meninggalkan_file(): void
    {
        $reseller = Admin::factory()->reseller()->create();
        $paket = PaketInternet::factory()->create(['reseller_id' => $reseller->id]);
        $token = $reseller->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/reseller/pelanggan', [
                'nama_lengkap' => 'Non Gambar',
                'nik' => '5555444433332222',
                'nomor_hp' => '085555555555',
                'email' => 'non.gambar@example.com',
                'alamat_pemasangan' => 'Jl. Salah No. 1',
                'tipe_paket' => 'reguler',
                'paket_internet_id' => $paket->id,
                'foto_ktp' => UploadedFile::fake()->create('ktp.pdf', 100, 'application/pdf'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('foto_ktp');

        $this->assertSame([], Storage::disk('private')->allFiles('ktp'));
        $this->assertSame([], Storage::disk('public')->allFiles('ktp'));
    }

    public function test_preview_tetap_melayani_file_legacy_plaintext_di_public_selama_migrasi(): void
    {
        // Backup kompatibilitas: file plaintext lama di public tetap bisa disajikan
        // selama masa migrasi `php artisan ktp:encrypt-existing`.
        $pathLegacy = KompresiGambar::simpanKeWebp(
            UploadedFile::fake()->image('ktp.jpg'),
            'ktp',
            enkripsi: false,
            disk: 'public',
        );

        $pelanggan = Pelanggan::factory()->create(['foto_ktp' => $pathLegacy]);

        $admin = Admin::factory()->operasional()->create();
        $token = $admin->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/admin/operasional/pelanggan/{$pelanggan->id}/foto-ktp");

        $response->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->assertStringStartsWith("\x52\x49\x46\x46", $response->getContent());
    }

    public function test_path_dari_record_pelanggan_yang_tidak_aman_tidak_dapat_dibaca(): void
    {
        $admin = Admin::factory()->operasional()->create();
        $token = $admin->createToken('test')->plainTextToken;

        // Path traversal / absolute path yang nyasar di record → 404, bukan baca file.
        foreach ([
            '../../../../etc/passwd',
            'ktp/../../../etc/passwd',
            '/etc/passwd',
            '..\\..\\windows\\win.ini',
        ] as $pathBerbahaya) {
            $pelanggan = Pelanggan::factory()->create(['foto_ktp' => $pathBerbahaya]);

            $this->withHeader('Authorization', "Bearer {$token}")
                ->getJson("/api/admin/operasional/pelanggan/{$pelanggan->id}/foto-ktp")
                ->assertNotFound();
        }
    }
}