<?php

namespace Tests\Feature\Api;

use App\Enums\PeranAdminEnum;
use App\Models\Admin;
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
            ->assertJsonPath('data.reseller.peran', PeranAdminEnum::RESELLER);

        $shadowToken = $response->json('data.token');

        // Reset guard auth supaya token baru benar-benar di-resolve (guard
        // sanctum men-cache user antar-request dalam satu test).
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$shadowToken}")
            ->getJson('/api/reseller/profil')
            ->assertOk()
            ->assertJsonPath('data.nama_lengkap', $reseller->nama_lengkap);
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

        // Token lama tetap ada + 1 token shadow baru.
        $this->assertSame(2, $reseller->tokens()->count());
        $this->assertNotNull($reseller->tokens()->where('name', 'portal')->first());
    }
}