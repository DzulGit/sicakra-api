<?php

namespace Tests\Feature\Api;

use App\Enums\PeranAdminEnum;
use App\Models\Admin;
use App\Models\ShadowSesi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResellerShadowTest extends TestCase
{
    use RefreshDatabase;

    public function test_operasional_bisa_shadow_login_reseller(): void
    {
        $operasional = Admin::factory()->operasional()->create();
        $token = $operasional->createToken('test')->plainTextToken;

        $reseller = Admin::factory()->reseller()->create();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/admin/operasional/reseller/{$reseller->id}/shadow");

        $response->assertOk()
            ->assertJsonPath('data.reseller.id', $reseller->id)
            ->assertJsonPath('data.reseller.peran', PeranAdminEnum::RESELLER)
            ->assertJsonStructure(['data' => ['kode']]);

        // Klaim kode → token shadow berumur pendek.
        $klaim = $this->postJson('/api/reseller/shadow/klaim', [
            'kode' => $response->json('data.kode'),
        ]);

        $klaim->assertOk()
            ->assertJsonPath('data.reseller.id', $reseller->id)
            ->assertJsonPath('data.admin.id', $operasional->id)
            ->assertJsonStructure(['data' => ['token']]);

        $shadowToken = $klaim->json('data.token');

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$shadowToken}")
            ->getJson('/api/reseller/profil')
            ->assertOk()
            ->assertJsonPath('data.nama_lengkap', $reseller->nama_lengkap);
    }

    public function test_kode_shadow_hanya_bisa_dipakai_sekali(): void
    {
        $operasional = Admin::factory()->operasional()->create();
        $token = $operasional->createToken('test')->plainTextToken;

        $reseller = Admin::factory()->reseller()->create();

        $kode = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/admin/operasional/reseller/{$reseller->id}/shadow")
            ->json('data.kode');

        $this->postJson('/api/reseller/shadow/klaim', ['kode' => $kode])->assertOk();

        $this->postJson('/api/reseller/shadow/klaim', ['kode' => $kode])
            ->assertUnprocessable();
    }

    public function test_kode_shadow_salah_atau_kedaluwarsa_ditolak(): void
    {
        $this->postJson('/api/reseller/shadow/klaim', ['kode' => 'salah'])
            ->assertUnprocessable();

        $operasional = Admin::factory()->operasional()->create();
        $reseller = Admin::factory()->reseller()->create();

        ShadowSesi::create([
            'admin_id' => $operasional->id,
            'reseller_id' => $reseller->id,
            'kode_hash' => hash('sha256', 'kode-kedaluwarsa'),
            'kode_kedaluwarsa_pada' => now()->subMinute(),
        ]);

        $this->postJson('/api/reseller/shadow/klaim', ['kode' => 'kode-kedaluwarsa'])
            ->assertUnprocessable();
    }

    public function test_bukan_reseller_tidak_bisa_dishadow(): void
    {
        $operasional = Admin::factory()->operasional()->create();
        $token = $operasional->createToken('test')->plainTextToken;

        $teknisi = Admin::factory()->teknisi()->create();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/admin/operasional/reseller/{$teknisi->id}/shadow")
            ->assertNotFound();
    }

    public function test_reseller_nonaktif_tidak_bisa_dishadow(): void
    {
        $operasional = Admin::factory()->operasional()->create();
        $token = $operasional->createToken('test')->plainTextToken;

        $reseller = Admin::factory()->reseller()->create(['status_aktif' => false]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/admin/operasional/reseller/{$reseller->id}/shadow")
            ->assertNotFound();
    }

    public function test_non_operasional_tidak_bisa_shadow_login(): void
    {
        $teknisi = Admin::factory()->teknisi()->create();
        $token = $teknisi->createToken('test')->plainTextToken;

        $reseller = Admin::factory()->reseller()->create();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/admin/operasional/reseller/{$reseller->id}/shadow")
            ->assertForbidden();
    }

    public function test_shadow_login_tidak_menghapus_token_reseller(): void
    {
        $operasional = Admin::factory()->operasional()->create();
        $token = $operasional->createToken('test')->plainTextToken;

        $reseller = Admin::factory()->reseller()->create();
        $reseller->createToken('portal')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/admin/operasional/reseller/{$reseller->id}/shadow")
            ->assertOk();

        // Token lama reseller tidak tersentuh (belum klaim).
        $this->assertSame(1, $reseller->tokens()->count());
        $this->assertNotNull($reseller->tokens()->where('name', 'portal')->first());
    }

    public function test_admin_bisa_membatalkan_shadow_aktif(): void
    {
        $operasional = Admin::factory()->operasional()->create();
        $token = $operasional->createToken('test')->plainTextToken;

        $reseller = Admin::factory()->reseller()->create();

        $kode = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/admin/operasional/reseller/{$reseller->id}/shadow")
            ->json('data.kode');

        $shadowToken = $this->postJson('/api/reseller/shadow/klaim', ['kode' => $kode])->json('data.token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/admin/operasional/reseller/{$reseller->id}/shadow")
            ->assertOk();

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$shadowToken}")
            ->getJson('/api/reseller/profil')
            ->assertStatus(401);
    }

    public function test_shadow_diakhiri_via_selesai(): void
    {
        $operasional = Admin::factory()->operasional()->create();
        $token = $operasional->createToken('test')->plainTextToken;

        $reseller = Admin::factory()->reseller()->create();

        $kode = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/admin/operasional/reseller/{$reseller->id}/shadow")
            ->json('data.kode');

        $shadowToken = $this->postJson('/api/reseller/shadow/klaim', ['kode' => $kode])->json('data.token');

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$shadowToken}")
            ->postJson('/api/reseller/shadow/selesai')
            ->assertOk();

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$shadowToken}")
            ->getJson('/api/reseller/profil')
            ->assertStatus(401);

        // Sesi shadow tetap tersimpan (diakhiri) beserta jejak audit-nya,
        // walau token-nya sudah dicabut.
        $this->assertDatabaseHas('shadow_sesi', [
            'admin_id' => $operasional->id,
            'reseller_id' => $reseller->id,
        ]);
    }

    public function test_logout_admin_menonaktifkan_semua_shadow_miliknya(): void
    {
        $operasional = Admin::factory()->operasional()->create();
        $token = $operasional->createToken('test')->plainTextToken;

        $reseller = Admin::factory()->reseller()->create();

        $kode = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/admin/operasional/reseller/{$reseller->id}/shadow")
            ->json('data.kode');

        $shadowToken = $this->postJson('/api/reseller/shadow/klaim', ['kode' => $kode])->json('data.token');

        // Logout admin.
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/admin/logout')
            ->assertOk();

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$shadowToken}")
            ->getJson('/api/reseller/profil')
            ->assertStatus(401);
    }

    public function test_aksi_mutasi_shadow_tercatat_di_audit(): void
    {
        $operasional = Admin::factory()->operasional()->create();
        $token = $operasional->createToken('test')->plainTextToken;

        $reseller = Admin::factory()->reseller()->create();

        $kode = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/admin/operasional/reseller/{$reseller->id}/shadow")
            ->json('data.kode');

        $shadowToken = $this->postJson('/api/reseller/shadow/klaim', ['kode' => $kode])->json('data.token');

        $this->app['auth']->forgetGuards();

        // PATCH profil = aksi mutasi, harus terekam.
        $this->withHeader('Authorization', "Bearer {$shadowToken}")
            ->patchJson('/api/reseller/profil', ['nama_lengkap' => 'Nama Baru'])
            ->assertOk();

        $this->assertDatabaseHas('shadow_aktivitas', [
            'admin_id' => $operasional->id,
            'reseller_id' => $reseller->id,
            'method' => 'PATCH',
            'path' => 'api/reseller/profil',
        ]);
    }

    public function test_aksi_baca_shadow_tidak_tercatat_berlebihan(): void
    {
        $operasional = Admin::factory()->operasional()->create();
        $token = $operasional->createToken('test')->plainTextToken;

        $reseller = Admin::factory()->reseller()->create();

        $kode = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/admin/operasional/reseller/{$reseller->id}/shadow")
            ->json('data.kode');

        $shadowToken = $this->postJson('/api/reseller/shadow/klaim', ['kode' => $kode])->json('data.token');

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$shadowToken}")
            ->getJson('/api/reseller/profil')
            ->assertOk();

        $this->assertDatabaseCount('shadow_aktivitas', 0);
    }
}