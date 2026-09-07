<?php

namespace Tests\Feature\Api;

use App\Enums\PeranAdminEnum;
use App\Models\Admin;
use App\Models\PaketInternet;
use App\Notifications\PelangganBaruDariResellerNotification;
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

    public function test_reseller_mendaftarkan_pelanggan_triggers_notifikasi_ke_operasional(): void
    {
        $operasional = Admin::factory()->operasional()->create();
        $superAdmin = Admin::factory()->superAdmin()->create();
        $reseller = Admin::factory()->reseller()->create();
        $paket = PaketInternet::factory()->create();
        $token = $reseller->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/admin/operasional/pelanggan/buat-baru', [
                'nama_lengkap' => 'Andi Test',
                'nik' => '1234567890123456',
                'nomor_hp' => '081234567890',
                'email' => 'andi.test@example.com',
                'alamat_pemasangan' => 'Jl. Test No. 1',
                'latitude' => -6.2,
                'longitude' => 106.8,
                'tipe_paket' => 'reguler',
                'paket_internet_id' => $paket->id,
                'foto_ktp' => UploadedFile::fake()->image('ktp.jpg'),
            ])
            ->assertCreated();

        $this->assertDatabaseHas('pelanggan', [
            'email' => 'andi.test@example.com',
            'reseller_id' => $reseller->id,
        ]);

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $operasional->id,
            'notifiable_type' => Admin::class,
            'type' => PelangganBaruDariResellerNotification::class,
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $superAdmin->id,
            'notifiable_type' => Admin::class,
            'type' => PelangganBaruDariResellerNotification::class,
        ]);
    }
}