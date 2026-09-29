<?php

namespace Tests\Feature\Api\Operasional;

use App\Models\Admin;
use App\Models\PermohonanLayanan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardOperasionalTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = Admin::factory()->operasional()->create()->createToken('test')->plainTextToken;
    }

    public function test_permohonan_terbaru_dibatasi_lima_baris(): void
    {
        PermohonanLayanan::factory()->count(7)->create();

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/admin/operasional/dashboard')
            ->assertOk()
            ->assertJsonCount(5, 'data.permohonan_terbaru');
    }
}
