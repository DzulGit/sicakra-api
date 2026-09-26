<?php

namespace Tests\Feature\Api\SuperAdmin;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UbahAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_ubah_admin_memerlukan_password_super_admin(): void
    {
        $superAdmin = Admin::factory()->superAdmin()->create(['password' => 'rahasia123']);
        Sanctum::actingAs($superAdmin);

        $target = Admin::factory()->keuangan()->create();

        $this->patchJson("/api/admin/super-admin/admin/{$target->id}", [
            'nama_lengkap' => 'Budi Baru',
            'password_superadmin' => 'salah',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('password_superadmin');
    }

    public function test_ubah_admin_format_data_dengan_password_super_admin_benar(): void
    {
        $superAdmin = Admin::factory()->superAdmin()->create(['password' => 'rahasia123']);
        Sanctum::actingAs($superAdmin);

        $target = Admin::factory()->keuangan()->create();

        $this->patchJson("/api/admin/super-admin/admin/{$target->id}", [
            'nama_lengkap' => 'Budi Baru',
            'email' => 'budi.baru@sicakra.com',
            'password_superadmin' => 'rahasia123',
        ])->assertOk();

        $this->assertDatabaseHas('admin', [
            'id' => $target->id,
            'nama_lengkap' => 'Budi Baru',
            'email' => 'budi.baru@sicakra.com',
        ]);
    }

    public function test_ganti_password_mengharuskan_password_super_admin_dan_mengubah_password_target(): void
    {
        $superAdmin = Admin::factory()->superAdmin()->create(['password' => 'rahasia123']);
        Sanctum::actingAs($superAdmin);

        $target = Admin::factory()->operasional()->create();

        $this->patchJson("/api/admin/super-admin/admin/{$target->id}", [
            'password_baru' => 'passwordbaru99',
            'password_superadmin' => 'rahasia123',
        ])->assertOk();

        $this->assertTrue(Hash::check('passwordbaru99', $target->fresh()->password));
    }

    public function test_ubah_email_duplikat_tetap_ditolak(): void
    {
        $superAdmin = Admin::factory()->superAdmin()->create(['password' => 'rahasia123']);
        Sanctum::actingAs($superAdmin);

        $lain = Admin::factory()->teknisi()->create();
        $target = Admin::factory()->keuangan()->create();

        $this->patchJson("/api/admin/super-admin/admin/{$target->id}", [
            'email' => $lain->email,
            'password_superadmin' => 'rahasia123',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }
}