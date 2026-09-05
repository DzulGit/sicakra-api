<?php

namespace Tests\Feature\Auth;

use App\Models\Pelanggan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginPelangganTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_pertama_berhasil_dan_wajib_buat_password(): void
    {
        Pelanggan::factory()->create([
            'nomor_pelanggan' => 'PLG000001',
            'nomor_hp' => '081234567890',
            'password_sudah_dibuat' => false,
        ]);

        $response = $this->postJson('/api/pelanggan/login-pertama', [
            'nomor_pelanggan' => 'PLG000001',
            'nomor_hp' => '081234567890',
        ]);

        $response->assertOk()->assertJsonPath('data.wajib_buat_password', true);
    }

    public function test_login_pertama_gagal_kalau_password_sudah_pernah_dibuat(): void
    {
        Pelanggan::factory()->sudahAktif()->create([
            'nomor_pelanggan' => 'PLG000002',
            'nomor_hp' => '081234567891',
        ]);

        $response = $this->postJson('/api/pelanggan/login-pertama', [
            'nomor_pelanggan' => 'PLG000002',
            'nomor_hp' => '081234567891',
        ]);

        $response->assertStatus(422);
    }

    public function test_pelanggan_wajib_buat_password_sebelum_akses_dashboard(): void
    {
        $pelanggan = Pelanggan::factory()->create([
            'nomor_pelanggan' => 'PLG000003',
            'password_sudah_dibuat' => false,
        ]);

        $token = $pelanggan->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/pelanggan/profil');

        $response->assertStatus(403);
    }

    public function test_pelanggan_bisa_buat_password_lalu_akses_dashboard(): void
    {
        $pelanggan = Pelanggan::factory()->create([
            'nomor_pelanggan' => 'PLG000004',
            'password_sudah_dibuat' => false,
        ]);

        $token = $pelanggan->createToken('test')->plainTextToken;

        $buatPassword = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/pelanggan/buat-password', [
                'password' => 'passwordbaru123',
                'password_confirmation' => 'passwordbaru123',
            ]);

        $buatPassword->assertOk();

        $tokenBaru = $buatPassword->json('data.token');

        $this->withHeader('Authorization', "Bearer {$tokenBaru}")
            ->getJson('/api/pelanggan/profil')
            ->assertOk();
    }

    public function test_login_normal_berhasil_setelah_password_dibuat(): void
    {
        $pelanggan = Pelanggan::factory()->sudahAktif()->create([
            'password' => Hash::make('password123'),
        ]);

        $response = $this->postJson('/api/pelanggan/login', [
            'username' => $pelanggan->username,
            'password' => 'password123',
        ]);

        $response->assertOk();
    }
}
