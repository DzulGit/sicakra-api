<?php

namespace Tests\Feature\Notifikasi;

use App\Enums\PeranAdminEnum;
use App\Models\Admin;
use App\Models\LayananInternet;
use App\Models\PaketInternet;
use App\Models\Pelanggan;
use App\Models\PermohonanLayanan;
use App\Models\Tagihan;
use App\Notifications\LaporanKendalaBaruNotification;
use App\Notifications\PembayaranTagihanNotification;
use App\Notifications\PendaftaranSelesaiNotification;
use App\Notifications\PendaftarBaruNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class NotifikasiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_admin_operasional_dan_super_admin_mendapat_notifikasi_saat_pendaftar_baru(): void
    {
        $operasional = Admin::factory()->operasional()->create();
        $superAdmin = Admin::factory()->superAdmin()->create();
        Admin::factory()->keuangan()->create();

        $this->postJson('/api/pendaftaran', $this->payloadPendaftaran())
            ->assertCreated();

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $operasional->id,
            'notifiable_type' => Admin::class,
            'type' => PendaftarBaruNotification::class,
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $superAdmin->id,
            'notifiable_type' => Admin::class,
            'type' => PendaftarBaruNotification::class,
        ]);
        $this->assertEquals(0, Admin::firstWhere('peran', PeranAdminEnum::KEUANGAN)->notifications()->count());
    }

    public function test_pelanggan_mendapat_notifikasi_database_pendaftaran_selesai(): void
    {
        $this->postJson('/api/pendaftaran', $this->payloadPendaftaran())
            ->assertCreated();

        $pelanggan = Pelanggan::first();

        $notifikasi = $pelanggan->notifications()->first();
        $this->assertEquals(PendaftaranSelesaiNotification::class, $notifikasi->type);
        $this->assertEquals('Pendaftaran Berhasil', $notifikasi->data['title']);
        $this->assertEquals('pendaftaran', $notifikasi->data['type']);
        $this->assertEquals('/pelanggan/dashboard', $notifikasi->data['action_url']);
    }

    public function test_admin_mendapat_notifikasi_saat_pelanggan_buat_laporan_kendala(): void
    {
        $operasional = Admin::factory()->operasional()->create();

        $pelanggan = Pelanggan::factory()->sudahAktif()->create();
        $layanan = LayananInternet::factory()->create(['pelanggan_id' => $pelanggan->id]);

        $token = $pelanggan->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/pelanggan/laporan-kendala', [
                'layanan_internet_id' => $layanan->id,
                'kategori_kendala' => 'Internet Lambat',
                'deskripsi' => 'Internet lambat sejak pagi.',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $operasional->id,
            'notifiable_type' => Admin::class,
            'type' => LaporanKendalaBaruNotification::class,
        ]);
    }

    public function test_api_list_notifikasi_dan_badge_unread_count(): void
    {
        $admin = Admin::factory()->operasional()->create();
        $admin->notify(new PendaftarBaruNotification($this->buatPermohonan()));

        $token = $admin->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/notifikasi');

        $response->assertOk()
            ->assertJsonPath('meta.unread_count', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Pendaftar Baru')
            ->assertJsonPath('data.0.type', 'pendaftaran')
            ->assertJsonPath('data.0.action_url', '/admin/operasional/permohonan-layanan/1')
            ->assertJsonPath('data.0.read_at', null);
    }

    public function test_api_tandai_dibaca_dan_tandai_semua_dibaca(): void
    {
        $admin = Admin::factory()->operasional()->create();
        $modul = $admin->notifications();

        for ($i = 0; $i < 3; $i++) {
            $admin->notify(new PendaftarBaruNotification($this->buatPermohonan()));
        }

        $token = $admin->createToken('test')->plainTextToken;
        $idPertama = $admin->notifications()->first()->id;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/admin/notifikasi/{$idPertama}/dibaca")
            ->assertOk();

        $this->assertEquals(2, $admin->unreadNotifications()->count());

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/admin/notifikasi/dibaca-semua')
            ->assertOk();

        $this->assertEquals(0, $admin->unreadNotifications()->count());
    }

    public function test_admin_keuangan_mendapat_notifikasi_saat_pembayaran_berhasil_via_webhook(): void
    {
        Config::set('services.xendit.webhook_verification_token', 'test-webhook-token');
        $keuangan = Admin::factory()->keuangan()->create();
        $superAdmin = Admin::factory()->superAdmin()->create();

        $pelanggan = Pelanggan::factory()->sudahAktif()->create();
        $layanan = LayananInternet::factory()->create(['pelanggan_id' => $pelanggan->id]);
        $tagihan = Tagihan::factory()->create([
            'nomor_tagihan' => 'INV000001',
            'total_tagihan' => 150000,
            'layanan_internet_id' => $layanan->id,
        ]);

        $this->postJson('/api/webhook/xendit', [
            'external_id' => 'TGH-INV000001',
            'id' => 'xendit-inv-123',
            'status' => 'PAID',
            'paid_amount' => 150000,
            'payment_method' => 'BCA',
        ], ['X-Callback-Token' => 'test-webhook-token'])
            ->assertOk();

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $keuangan->id,
            'notifiable_type' => Admin::class,
            'type' => PembayaranTagihanNotification::class,
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $superAdmin->id,
            'notifiable_type' => Admin::class,
            'type' => PembayaranTagihanNotification::class,
        ]);
    }

    private function payloadPendaftaran(): array
    {
        $paket = PaketInternet::factory()->create();

        return [
            'nama_lengkap' => 'Budi Santoso',
            'nik' => '1234567890123456',
            'nomor_hp' => '081234567890',
            'email' => 'budi@example.com',
            'alamat_pemasangan' => 'Jl. Merdeka No. 1',
            'rt' => '001',
            'rw' => '002',
            'kode_pos' => '50000',
            'latitude' => -6.2,
            'longitude' => 106.8,
            'tipe_paket' => 'reguler',
            'paket_internet_id' => $paket->id,
            'foto_ktp' => UploadedFile::fake()->image('ktp.jpg'),
            'foto_selfie_ktp' => UploadedFile::fake()->image('selfie.jpg'),
        ];
    }

    private function buatPermohonan(): PermohonanLayanan
    {
        return PermohonanLayanan::factory()->create();
    }
}
