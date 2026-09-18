<?php

namespace Tests\Feature\Api\Keuangan;

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

class TagihanWorkflowPembentukanTerbitkanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
    }

    private function layananAktif(Pelanggan $pelanggan): LayananInternet
    {
        return LayananInternet::factory()->create([
            'pelanggan_id' => $pelanggan->id,
            'status' => StatusLayananEnum::AKTIF,
        ]);
    }

    public function test_generate_manual_membentuk_draft_belum_diterbitkan(): void
    {
        $admin = Admin::factory()->keuangan()->create();
        $pelanggan = Pelanggan::factory()->create();
        $layanan = $this->layananAktif($pelanggan);

        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/keuangan/tagihan/generate/{$pelanggan->id}", [
            'periode_bulan' => now()->month,
            'periode_tahun' => now()->year,
        ])->assertCreated();

        $tagihan = Tagihan::where('layanan_internet_id', $layanan->id)->firstOrFail();

        $this->assertSame(StatusPembayaranEnum::BELUM_DITERBITKAN, $tagihan->status_pembayaran);
        $this->assertNull($tagihan->diterbitkan_pada);
        Event::assertNotDispatched(TagihanDibuat::class);
    }

    public function test_generate_tagihan_pertama_membentuk_draft_belum_diterbitkan(): void
    {
        $admin = Admin::factory()->keuangan()->create();
        $pelanggan = Pelanggan::factory()->create();
        $layanan = $this->layananAktif($pelanggan);

        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/keuangan/tagihan/pertama/{$pelanggan->id}", [
            'layanan_internet_id' => $layanan->id,
            'mode' => 'full',
        ])->assertCreated();

        $tagihan = Tagihan::where('layanan_internet_id', $layanan->id)->firstOrFail();

        $this->assertSame(StatusPembayaranEnum::BELUM_DITERBITKAN, $tagihan->status_pembayaran);
        Event::assertNotDispatched(TagihanDibuat::class);
    }

    public function test_draft_first_timer_muncul_di_draft_index_dan_bisa_diterbitkan(): void
    {
        $admin = Admin::factory()->keuangan()->create();
        $pelanggan = Pelanggan::factory()->create([
            'nama_lengkap' => 'Pelanggan Baru Sekali',
        ]);
        $layanan = $this->layananAktif($pelanggan);

        $draft = Tagihan::factory()->create([
            'layanan_internet_id' => $layanan->id,
            'periode_bulan' => now()->month,
            'periode_tahun' => now()->year,
            'status_pembayaran' => StatusPembayaranEnum::BELUM_DITERBITKAN,
        ]);

        Sanctum::actingAs($admin);

        // Pelanggan ini BELUM pernah punya tagihan terbit — tetap wajib muncul.
        $this->getJson('/api/admin/keuangan/tagihan/draft?search=Pelanggan%20Baru')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $draft->id);

        $this->postJson('/api/admin/keuangan/tagihan/terbitkan', [
            'tagihan_ids' => [$draft->id],
        ])->assertOk()
            ->assertJsonPath('berhasil', 1)
            ->assertJsonPath('gagal', 0);

        $draft->refresh();
        $this->assertSame(StatusPembayaranEnum::BELUM_BAYAR, $draft->status_pembayaran);
        $this->assertNotNull($draft->diterbitkan_pada);
        Event::assertDispatched(TagihanDibuat::class);
    }

    public function test_terbitkan_menolak_tagihan_yang_bukan_draft(): void
    {
        $admin = Admin::factory()->keuangan()->create();
        $pelanggan = Pelanggan::factory()->create();
        $layanan = $this->layananAktif($pelanggan);

        $tagihan = Tagihan::factory()->create([
            'layanan_internet_id' => $layanan->id,
            'status_pembayaran' => StatusPembayaranEnum::BELUM_BAYAR,
        ]);

        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/keuangan/tagihan/terbitkan', [
            'tagihan_ids' => [$tagihan->id],
        ])->assertOk()
            ->assertJsonPath('berhasil', 0)
            ->assertJsonPath('gagal', 1);

        $this->assertNull($tagihan->fresh()->diterbitkan_pada);
    }

    public function test_bayar_tunai_draft_ditolak(): void
    {
        $admin = Admin::factory()->keuangan()->create();
        $pelanggan = Pelanggan::factory()->create();
        $layanan = $this->layananAktif($pelanggan);

        $draft = Tagihan::factory()->create([
            'layanan_internet_id' => $layanan->id,
            'status_pembayaran' => StatusPembayaranEnum::BELUM_DITERBITKAN,
        ]);

        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/keuangan/tagihan/{$draft->id}/bayar-tunai", [
            'jumlah_dibayar' => 100000,
        ])->assertStatus(422)->assertJsonPath('message', 'Tagihan belum diterbitkan.');

        $this->assertDatabaseCount('pembayaran', 0);
    }

    public function test_perbarui_link_draft_ditolak(): void
    {
        $admin = Admin::factory()->keuangan()->create();
        $pelanggan = Pelanggan::factory()->create();
        $layanan = $this->layananAktif($pelanggan);

        $draft = Tagihan::factory()->create([
            'layanan_internet_id' => $layanan->id,
            'status_pembayaran' => StatusPembayaranEnum::BELUM_DITERBITKAN,
        ]);

        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/keuangan/tagihan/{$draft->id}/perbarui-link")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Tagihan belum diterbitkan.');
    }

    public function test_pelanggan_show_menyertakan_ringkasan_dan_field_keuangan_tanpa_draft(): void
    {
        $admin = Admin::factory()->keuangan()->create();
        $pelanggan = Pelanggan::factory()->create();
        $layanan = $this->layananAktif($pelanggan);

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

        Sanctum::actingAs($admin);

        $json = $this->getJson("/api/admin/operasional/pelanggan/{$pelanggan->id}")
            ->assertOk()
            ->assertJsonPath('data.ringkasan_tagihan.belum_bayar', 1)
            ->assertJsonPath('data.ringkasan_tagihan.sedang_cicil', 0)
            ->assertJsonPath('data.ringkasan_tagihan.tertunggak', 0)
            ->assertJsonPath('data.ringkasan_tagihan.lunas', 0);

        $idTagihan = collect($json->json('data.layanan_internet.0.tagihan'))->pluck('id')->all();

        $this->assertContains($terbit->id, $idTagihan);
        $this->assertContains($draft->id, $idTagihan);

        $itemTerbit = collect($json->json('data.layanan_internet.0.tagihan'))
            ->firstWhere('id', $terbit->id);
        $this->assertSame('Belum Bayar', $itemTerbit['status']);
        $this->assertSame(0, $itemTerbit['telah_terbayar']);
        $this->assertSame(-250000, $itemTerbit['sisa']);

        $itemDraft = collect($json->json('data.layanan_internet.0.tagihan'))
            ->firstWhere('id', $draft->id);
        $this->assertSame('Belum Diterbitkan', $itemDraft['status']);
        $this->assertSame('belum_diterbitkan', $itemDraft['status_tampilan']);
    }

    public function test_tagihan_saya_menyembunyikan_draft_dan_menolak_pembayaran_draft(): void
    {
        $pelanggan = Pelanggan::factory()->sudahAktif()->create();
        $layanan = $this->layananAktif($pelanggan);

        Tagihan::factory()->create([
            'layanan_internet_id' => $layanan->id,
            'periode_bulan' => now()->month,
            'periode_tahun' => now()->year,
            'status_pembayaran' => StatusPembayaranEnum::BELUM_BAYAR,
        ]);

        $berikut = now()->addMonth();
        $draft = Tagihan::factory()->create([
            'layanan_internet_id' => $layanan->id,
            'periode_bulan' => $berikut->month,
            'periode_tahun' => $berikut->year,
            'status_pembayaran' => StatusPembayaranEnum::BELUM_DITERBITKAN,
        ]);

        Sanctum::actingAs($pelanggan);

        $this->getJson('/api/pelanggan/tagihan')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonMissing(['id' => $draft->id]);

        $this->postJson("/api/pelanggan/tagihan/{$draft->id}/bayar", [
            'jumlah_dibayar' => 100000,
        ])->assertStatus(422)->assertJsonPath('message', 'Tagihan belum diterbitkan.');

        $this->postJson("/api/pelanggan/tagihan/{$draft->id}/regenerate-invoice")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Tagihan belum diterbitkan.');
    }
}
