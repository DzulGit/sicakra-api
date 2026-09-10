<?php

namespace Tests\Feature\Api;

use App\Models\Admin;
use App\Models\PaketInternet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResellerPaketInternetTest extends TestCase
{
    use RefreshDatabase;

    public function test_reseller_dapat_crud_paket_internet_milik_sendiri(): void
    {
        $reseller = Admin::factory()->reseller()->create();
        $token = $reseller->createToken('test')->plainTextToken;
        $auth = fn () => ['Authorization' => "Bearer {$token}"];

        $this->withHeaders($auth())
            ->postJson('/api/reseller/paket-internet', [
                'nama_paket' => 'Wifi 30 Mbps',
                'kecepatan_mbps' => 30,
                'harga' => 125000,
                'jumlah_perangkat' => 8,
                'deskripsi' => 'Untuk rumah',
            ])
            ->assertCreated()
            ->assertJsonPath('data.reseller_id', $reseller->id);

        $paket = PaketInternet::where('nama_paket', 'Wifi 30 Mbps')->first();
        $this->assertNotNull($paket);

        $this->withHeaders($auth())
            ->getJson('/api/reseller/paket-internet')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->withHeaders($auth())
            ->patchJson("/api/reseller/paket-internet/{$paket->id}", ['harga' => 150000])
            ->assertOk()
            ->assertJsonPath('data.harga', '150000.00');

        $this->withHeaders($auth())
            ->deleteJson("/api/reseller/paket-internet/{$paket->id}")
            ->assertOk();
    }

    public function test_reseller_tidak_bisa_mengubah_paket_reseller_lain(): void
    {
        $reseller = Admin::factory()->reseller()->create();
        $lain = Admin::factory()->reseller()->create();
        $paketLain = PaketInternet::factory()->create(['reseller_id' => $lain->id]);
        $token = $reseller->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/reseller/paket-internet/{$paketLain->id}", ['harga' => 100])
            ->assertNotFound();
    }
}