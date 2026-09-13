<?php

namespace Tests\Unit\Services;

use App\Models\Tagihan;
use App\Services\GeneratorNomorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeneratorNomorServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_nomor_mengikuti_sequence_prefix_yang_sama(): void
    {
        Tagihan::factory()->create([
            'nomor_tagihan' => 'INV000050',
        ]);

        Tagihan::factory()->create([
            'nomor_tagihan' => 'INVTEST000052',
        ]);

        $nomor = app(GeneratorNomorService::class)->generate(
            Tagihan::class,
            'nomor_tagihan',
            'INV',
        );

        $this->assertSame('INV000051', $nomor);
    }

    public function test_generate_nomor_dimulai_dari_satu_jika_belum_ada_prefix_yang_sesuai(): void
    {
        Tagihan::factory()->create([
            'nomor_tagihan' => 'INVTEST000052',
        ]);

        $nomor = app(GeneratorNomorService::class)->generate(
            Tagihan::class,
            'nomor_tagihan',
            'INV',
        );

        $this->assertSame('INV000001', $nomor);
    }

    public function test_generate_nomor_mengabaikan_nomor_dengan_prefix_berbeda(): void
    {
        Tagihan::factory()->create([
            'nomor_tagihan' => 'PMH000999',
        ]);

        $nomor = app(GeneratorNomorService::class)->generate(
            Tagihan::class,
            'nomor_tagihan',
            'INV',
        );

        $this->assertSame('INV000001', $nomor);
    }
}
