<?php

namespace Tests\Feature\Api\Reseller;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ResellerProfilTest extends TestCase
{
    use RefreshDatabase;

    private function reseller(?string $email = null): Admin
    {
        return Admin::factory()->reseller()->create(
            $email ? ['email' => $email] : [],
        );
    }

    public function test_reseller_bisa_lihat_profil_sendiri(): void
    {
        $reseller = $this->reseller();

        Sanctum::actingAs($reseller);

        $this->getJson('/api/reseller/profil')
            ->assertOk()
            ->assertJsonPath('data.id', $reseller->id)
            ->assertJsonPath('data.nama_lengkap', $reseller->nama_lengkap)
            ->assertJsonMissing(['email_baru' => true]);
    }

    public function test_reseller_bisa_ubah_nama(): void
    {
        $reseller = $this->reseller();

        Sanctum::actingAs($reseller);

        $this->patchJson('/api/reseller/profil', ['nama_lengkap' => 'Rina Baharu'])
            ->assertOk()
            ->assertJsonPath('data.nama_lengkap', 'Rina Baharu');
    }

    public function test_reseller_ganti_password_wajib_password_lama(): void
    {
        $reseller = $this->reseller();

        Sanctum::actingAs($reseller);

        $this->patchJson('/api/reseller/profil/password', [
            'password_lama' => 'salah',
            'password' => 'rahasia123',
            'password_confirmation' => 'rahasia123',
        ])->assertStatus(422);

        $this->patchJson('/api/reseller/profil/password', [
            'password_lama' => 'password123',
            'password' => 'rahasia123',
            'password_confirmation' => 'rahasia123',
        ])->assertOk();

        $this->assertTrue(Hash::check('rahasia123', $reseller->fresh()->password));
    }

    public function test_reseller_minta_ganti_email_mencatat_email_baru(): void
    {
        $reseller = $this->reseller();

        Sanctum::actingAs($reseller);

        $this->postJson('/api/reseller/profil/email', ['email' => 'baru@example.com'])
            ->assertOk()
            ->assertJsonPath('data.email_baru', 'baru@example.com')
            ->assertJsonPath('data.email', $reseller->email);

        $this->assertDatabaseHas('admin', [
            'id' => $reseller->id,
            'email_baru' => 'baru@example.com',
        ]);
    }

    public function test_reseller_tidak_bisa_minta_email_yang_dipakai_akun_lain(): void
    {
        $this->reseller('milik@example.com');
        $penemu = $this->reseller();

        Sanctum::actingAs($penemu);

        $this->postJson('/api/reseller/profil/email', ['email' => 'milik@example.com'])
            ->assertStatus(422);
    }

    public function test_reseller_bisa_batalkan_permintaan_email(): void
    {
        $reseller = $this->reseller();
        $reseller->update(['email_baru' => 'baru@example.com']);

        Sanctum::actingAs($reseller);

        $this->deleteJson('/api/reseller/profil/email')
            ->assertOk()
            ->assertJsonPath('data.email_baru', null);

        $this->assertDatabaseHas('admin', ['id' => $reseller->id, 'email_baru' => null]);
    }

    public function test_operasional_setujui_email_merubah_email_reseller(): void
    {
        $operasional = Admin::factory()->operasional()->create();
        $reseller = $this->reseller();
        $reseller->update(['email_baru' => 'baru@example.com']);

        Sanctum::actingAs($operasional);

        $this->patchJson("/api/admin/operasional/reseller/{$reseller->id}/setujui-email")
            ->assertOk()
            ->assertJsonPath('data.email', 'baru@example.com');

        $this->assertDatabaseHas('admin', ['id' => $reseller->id, 'email' => 'baru@example.com', 'email_baru' => null]);
    }

    public function test_operasional_tolak_email_mengosongkan_email_baru(): void
    {
        $operasional = Admin::factory()->operasional()->create();
        $reseller = $this->reseller();
        $reseller->update(['email_baru' => 'baru@example.com']);

        Sanctum::actingAs($operasional);

        $this->patchJson("/api/admin/operasional/reseller/{$reseller->id}/tolak-email")
            ->assertOk()
            ->assertJsonPath('data.email_baru', null)
            ->assertJsonPath('data.email', $reseller->email);
    }

    public function test_teknisi_tidak_bisa_setujui_email(): void
    {
        $teknisi = Admin::factory()->teknisi()->create();
        $reseller = $this->reseller();
        $reseller->update(['email_baru' => 'baru@example.com']);

        Sanctum::actingAs($teknisi);

        $this->patchJson("/api/admin/operasional/reseller/{$reseller->id}/setujui-email")
            ->assertForbidden();
    }
}