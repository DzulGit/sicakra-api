<?php

namespace Tests\Feature\Api\Reseller;

use App\Enums\StatusLayananEnum;
use App\Enums\StatusPembayaranEnum;
use App\Events\TagihanDibuat;
use App\Models\Admin;
use App\Models\LayananInternet;
use App\Models\Pelanggan;
use App\Models\Tagihan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TagihanWorkflowTerbitkanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
    }

    private function resellerDenganLayanan(string $namaPelanggan = 'Pelanggan Reseller'): array
    {
        $reseller = Admin::factory()->reseller()->create();
        Sanctum::actingAs($reseller);

        $pelanggan = Pelanggan::factory()->create([
            'reseller_id' => $reseller->id,
            'nama_lengkap' => $namaPelanggan,
        ]);

        $layanan = LayananInternet::factory()->create([
            'pelanggan_id' => $pelanggan->id,
            'status' => StatusLayananEnum::AKTIF,
        ]);

        return [$reseller, $pelanggan, $layanan];
    }

    public function test_draft_first_timer_reseller_bisa_diterbitkan(): void
    {
        [, $pelanggan, $layanan] = $this->resellerDenganLayanan();

        $draft = Tagihan::factory()->create([
            'layanan_internet_id' => $layanan->id,
            'periode_bulan' => now()->month,
            'periode_tahun' => now()->year,
            'status_pembayaran' => StatusPembayaranEnum::BELUM_DITERBITKAN,
        ]);

        $this->getJson('/api/reseller/tagihan/draft')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $draft->id);

        $this->postJson('/api/reseller/tagihan/terbitkan', [
            'tagihan_ids' => [$draft->id],
        ])->assertOk()
            ->assertJsonPath('berhasil', 1)
            ->assertJsonPath('gagal', 0);

        $draft->refresh();
        $this->assertSame(StatusPembayaranEnum::BELUM_BAYAR, $draft->status_pembayaran);
        $this->assertNotNull($draft->diterbitkan_pada);
        Event::assertDispatched(TagihanDibuat::class);
    }

    public function test_reseller_bayar_tunai_draft_ditolak(): void
    {
        [, $pelanggan, $layanan] = $this->resellerDenganLayanan();

        $draft = Tagihan::factory()->create([
            'layanan_internet_id' => $layanan->id,
            'status_pembayaran' => StatusPembayaranEnum::BELUM_DITERBITKAN,
        ]);

        $this->postJson("/api/reseller/tagihan/{$draft->id}/bayar-tunai", [
            'jumlah_dibayar' => 100000,
        ])->assertStatus(422)->assertJsonPath('message', 'Tagihan belum diterbitkan.');

        $this->assertDatabaseCount('pembayaran', 0);
    }

    public function test_reseller_perbarui_link_draft_ditolak(): void
    {
        [, , $layanan] = $this->resellerDenganLayanan();

        $draft = Tagihan::factory()->create([
            'layanan_internet_id' => $layanan->id,
            'status_pembayaran' => StatusPembayaranEnum::BELUM_DITERBITKAN,
        ]);

        $this->postJson("/api/reseller/tagihan/{$draft->id}/perbarui-link")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Tagihan belum diterbitkan.');
    }

    public function test_pelanggan_show_reseller_ringkasan_keuangan_tanpa_draft(): void
    {
        [, $pelanggan, $layanan] = $this->resellerDenganLayanan();

        $terbit = Tagihan::factory()->create([
            'layanan_internet_id' => $layanan->id,
            'periode_bulan' => now()->month,
            'periode_tahun' => now()->year,
            'total_tagihan' => 250000,
            'status_pembayaran' => StatusPembayaranEnum::BELUM_BAYAR,
            'diterbitkan_pada' => now(),
        ]);

        $berikut = now()->addMonth();
        $draft = Tagihan::factory()->create([
            'layanan_internet_id' => $layanan->id,
            'periode_bulan' => $berikut->month,
            'periode_tahun' => $berikut->year,
            'status_pembayaran' => StatusPembayaranEnum::BELUM_DITERBITKAN,
        ]);

        $json = $this->getJson("/api/reseller/pelanggan/{$pelanggan->id}")
            ->assertOk()
            ->assertJsonPath('data.ringkasan_tagihan.belum_bayar', 1);

        $idTagihan = collect($json->json('data.layanan_internet.0.tagihan'))->pluck('id')->all();

        $this->assertContains($terbit->id, $idTagihan);
        $this->assertContains($draft->id, $idTagihan);

        $itemTerbit = collect($json->json('data.layanan_internet.0.tagihan'))
            ->firstWhere('id', $terbit->id);
        $this->assertSame('Belum Bayar', $itemTerbit['status']);

        $itemDraft = collect($json->json('data.layanan_internet.0.tagihan'))
            ->firstWhere('id', $draft->id);
        $this->assertSame('Belum Diterbitkan', $itemDraft['status']);
    }
}
