<?php

namespace Tests\Feature\Api\SuperAdmin;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NonaktifkanAktifkanAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_nonaktifkan_admin_memerlukan_password_super_admin(): void
    {
        $superAdmin = Admin::factory()->superAdmin()->create(['password' => 'rahasia123']);
        Sanctum::actingAs($superAdmin);

        $target = Admin::factory()->keuangan()->create();

        $this->patchJson("/api/admin/super-admin/admin/{$target->id}/nonaktifkan", [
            'password_superadmin' => 'salah',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('password_superadmin');

        $this->assertTrue($target->fresh()->status_aktif);
    }

    public function test_nonaktifkan_admin_dengan_password_benar_mematikan_dan_mencabut_token(): void
    {
        $superAdmin = Admin::factory()->superAdmin()->create(['password' => 'rahasia123']);
        Sanctum::actingAs($superAdmin);

        $target = Admin::factory()->keuangan()->create();
        $target->createToken('perangkat');

        $this->patchJson("/api/admin/super-admin/admin/{$target->id}/nonaktifkan", [
            'password_superadmin' => 'rahasia123',
        ])->assertOk();

        $this->assertTrue($target->fresh()->status_aktif === false);
        $this->assertSame(0, $target->tokens()->count());
    }

    public function test_tidak_bisa_menonaktifkan_akun_sendiri(): void
    {
        $superAdmin = Admin::factory()->superAdmin()->create(['password' => 'rahasia123']);
        Sanctum::actingAs($superAdmin);

        $this->patchJson("/api/admin/super-admin/admin/{$superAdmin->id}/nonaktifkan", [
            'password_superadmin' => 'rahasia123',
        ])->assertForbidden();
    }

    public function test_aktifkan_admin_dengan_password_salah_ditolak(): void
    {
        $superAdmin = Admin::factory()->superAdmin()->create(['password' => 'rahasia123']);
        Sanctum::actingAs($superAdmin);

        $target = Admin::factory()->keuangan()->create(['status_aktif' => false]);

        $this->patchJson("/api/admin/super-admin/admin/{$target->id}/aktifkan", [
            'password_superadmin' => 'salah',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('password_superadmin');

        $this->assertFalse($target->fresh()->status_aktif);
    }

    public function test_aktifkan_admin_dengan_password_benar_mengaktifkan_kembali(): void
    {
        $superAdmin = Admin::factory()->superAdmin()->create(['password' => 'rahasia123']);
        Sanctum::actingAs($superAdmin);

        $target = Admin::factory()->keuangan()->create(['status_aktif' => false]);

        $this->patchJson("/api/admin/super-admin/admin/{$target->id}/aktifkan", [
            'password_superadmin' => 'rahasia123',
        ])->assertOk();

        $this->assertTrue($target->fresh()->status_aktif);
    }
}