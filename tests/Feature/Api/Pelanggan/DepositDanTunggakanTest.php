<?php

namespace Tests\Feature\Api\Pelanggan;

use App\Enums\StatusLayananEnum;
use App\Enums\StatusPembayaranEnum;
use App\Enums\StatusTransaksiEnum;
use App\Models\LayananInternet;
use App\Models\MutasiSaldoKredit;
use App\Models\Pelanggan;
use App\Models\Pembayaran;
use App\Models\PembayaranTagihan;
use App\Models\Tagihan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DepositDanTunggakanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.xendit.webhook_verification_token', 'test-webhook-token');

        Http::fake([
            'api.xendit.co/*' => Http::response([
                'id' => 'xendit-inv-1',
                'external_id' => 'PAY-1',
                'invoice_url' => 'https://checkout.xendit.co/invoice/1',
                'amount' => 150000,
                'status' => 'PENDING',
                'expiry_date' => now()->addDays(3)->toIso8601String(),
            ], 200),
        ]);
    }

    private function buatPelanggan(): Pelanggan
    {
        $pelanggan = Pelanggan::factory()->sudahAktif()->create();

        Sanctum::actingAs($pelanggan);

        return $pelanggan;
    }

    private function buatTagihan(
        int $bulan,
        int $tahun,
        int $total = 150000
    ): array {
        $pelanggan = $this->buatPelanggan();

        $layanan = LayananInternet::factory()->create([
            'pelanggan_id' => $pelanggan->id,
            'status' => StatusLayananEnum::AKTIF,
        ]);

        $tagihan = Tagihan::factory()->create([
            'layanan_internet_id' => $layanan->id,
            'periode_bulan' => $bulan,
            'periode_tahun' => $tahun,
            'total_tagihan' => $total,
            'status_pembayaran' => StatusPembayaranEnum::BELUM_BAYAR,
        ]);

        return [$pelanggan, $tagihan];
    }

    public function test_deposit_kosong_saat_tidak_ada_mutasi(): void
    {
        $this->buatPelanggan();

        $this->getJson('/api/pelanggan/deposit')
            ->assertOk()
            ->assertJsonPath('data.saldo_deposit', 0);
    }

    public function test_deposit_menampilkan_saldo_dan_mutasi(): void
    {
        $pelanggan = $this->buatPelanggan();

        MutasiSaldoKredit::create([
            'pelanggan_id' => $pelanggan->id,
            'jenis' => 'kredit',
            'jumlah' => 50000,
            'keterangan' => 'Kelebihan pembayaran.',
        ]);

        $this->getJson('/api/pelanggan/deposit')
            ->assertOk()
            ->assertJsonPath('data.saldo_deposit', 50000)
            ->assertJsonCount(1, 'data.mutasi');
    }

    public function test_tunggakan_menghitung_sisa_tagihan(): void
    {
        [$pelanggan, $tagihan] = $this->buatTagihan(1, 2026, 150000);

        $pembayaran = Pembayaran::factory()->create([
            'pelanggan_id' => $pelanggan->id,
            'jumlah_dibayar' => 50000,
            'tagihan_terpilih' => [$tagihan->id],
            'status' => StatusTransaksiEnum::BERHASIL,
        ]);

        PembayaranTagihan::create([
            'pembayaran_id' => $pembayaran->id,
            'tagihan_id' => $tagihan->id,
            'jumlah_dialokasikan' => 50000,
        ]);

        $this->getJson('/api/pelanggan/tagihan/tunggakan')
            ->assertOk()
            ->assertJsonPath('data.jumlah_tagihan', 1)
            ->assertJsonPath('data.total_tunggakan', 100000)
            ->assertJsonPath('data.tagihan.0.sisa_tagihan', 100000)
            ->assertJsonPath('data.tagihan.0.sudah_dibayar', 50000);
    }

    public function test_riwayat_pembayaran_menampilkan_transaksi_pelanggan(): void
    {
        $pelanggan = $this->buatPelanggan();

        Pembayaran::factory()->create([
            'pelanggan_id' => $pelanggan->id,
            'jumlah_dibayar' => 150000,
            'status' => StatusTransaksiEnum::BERHASIL,
            'dibayar_pada' => now(),
        ]);

        $this->getJson('/api/pelanggan/pembayaran')
            ->assertOk()
            ->assertJsonCount(1, 'data.data');
    }

    public function test_pakai_deposit_melunasi_tunggakan(): void
    {
        [$pelanggan, $tagihan] = $this->buatTagihan(1, 2026, 150000);

        MutasiSaldoKredit::create([
            'pelanggan_id' => $pelanggan->id,
            'jenis' => 'kredit',
            'jumlah' => 150000,
            'keterangan' => 'Kelebihan pembayaran.',
        ]);

        $this->postJson('/api/pelanggan/deposit/gunakan')
            ->assertOk()
            ->assertJsonPath('data.total_digunakan', 150000)
            ->assertJsonPath('data.saldo_deposit', 0)
            ->assertJsonPath('data.tunggakan.total_tunggakan', 0);

        $this->assertSame(
            StatusPembayaranEnum::SUDAH_BAYAR,
            $tagihan->fresh()->status_pembayaran
        );
    }

    public function test_pakai_deposit_tanpa_saldo_tidak_mengubah_apa_apa(): void
    {
        $this->buatTagihan(1, 2026, 150000);

        $this->postJson('/api/pelanggan/deposit/gunakan')
            ->assertOk()
            ->assertJsonPath('data.total_digunakan', 0)
            ->assertJsonPath('data.saldo_deposit', 0);
    }

    public function test_bayar_gabungan_bisa_memilih_tagihan_dan_pakai_deposit(): void
    {
        [$pelanggan, $tagihan1] = $this->buatTagihan(1, 2026, 200000);
        $tagihan2 = Tagihan::factory()->create([
            'layanan_internet_id' => $tagihan1->layanan_internet_id,
            'periode_bulan' => 2,
            'periode_tahun' => 2026,
            'total_tagihan' => 100000,
            'status_pembayaran' => StatusPembayaranEnum::BELUM_BAYAR,
        ]);

        MutasiSaldoKredit::create([
            'pelanggan_id' => $pelanggan->id,
            'jenis' => 'kredit',
            'jumlah' => 100000,
            'keterangan' => 'Kelebihan pembayaran.',
        ]);

        $response = $this->postJson('/api/pelanggan/tagihan/bayar-gabungan', [
            'jumlah_dibayar' => 100000,
            'tagihan_ids' => [$tagihan2->id],
            'gunakan_deposit' => true,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.payment_url', 'https://checkout.xendit.co/invoice/1');

        $pembayaran = Pembayaran::find($response->json('data.pembayaran.id'));

        $this->assertSame((int) $tagihan2->id, $pembayaran->tagihan_terpilih[0]);
        $this->assertTrue($pembayaran->pakai_saldo_kredit);
    }

    public function test_detail_tagihan_menampilkan_riwayat_pembayaran_via_alokasi(): void
    {
        [$pelanggan, $tagihan] = $this->buatTagihan(1, 2026, 150000);

        $pembayaran = Pembayaran::factory()->create([
            'pelanggan_id' => $pelanggan->id,
            'jumlah_dibayar' => 50000,
            'tagihan_terpilih' => [$tagihan->id],
            'status' => StatusTransaksiEnum::BERHASIL,
        ]);

        PembayaranTagihan::create([
            'pembayaran_id' => $pembayaran->id,
            'tagihan_id' => $tagihan->id,
            'jumlah_dialokasikan' => 50000,
        ]);

        $this->getJson("/api/pelanggan/tagihan/{$tagihan->id}")
            ->assertOk()
            ->assertJsonPath('data.sisa_tagihan', 100000)
            ->assertJsonPath('data.sudah_dibayar', 50000)
            ->assertJsonCount(1, 'data.riwayat_pembayaran')
            ->assertJsonPath('data.riwayat_pembayaran.0.id', $pembayaran->id);
    }

    public function test_regenerate_invoice_hanya_mengalokasikan_tagihan_target(): void
    {
        [$pelanggan, $tagihanLama] = $this->buatTagihan(1, 2026, 100000);
        $tagihanBaru = Tagihan::factory()->create([
            'layanan_internet_id' => $tagihanLama->layanan_internet_id,
            'periode_bulan' => 2,
            'periode_tahun' => 2026,
            'total_tagihan' => 100000,
            'status_pembayaran' => StatusPembayaranEnum::BELUM_BAYAR,
        ]);

        $response = $this->postJson(
            "/api/pelanggan/tagihan/{$tagihanBaru->id}/regenerate-invoice"
        )->assertOk();

        $pembayaran = Pembayaran::find($response->json('data.id'));

        $this->assertSame([(int) $tagihanBaru->id], $pembayaran->tagihan_terpilih);

        $pembayaran->update([
            'provider_external_id' => 'PAY-' . $pembayaran->id,
            'status' => StatusTransaksiEnum::BERHASIL,
        ]);

        app(\App\Services\PembayaranAllocationService::class)
            ->selesaikanPembayaran($pembayaran, $pembayaran->tagihan_terpilih);

        $this->assertSame(
            StatusPembayaranEnum::SUDAH_BAYAR,
            $tagihanBaru->fresh()->status_pembayaran
        );

        $this->assertSame(
            StatusPembayaranEnum::BELUM_BAYAR,
            $tagihanLama->fresh()->status_pembayaran
        );
    }

    public function test_pelanggan_lain_tidak_bisa_membayar_tagihan_orang_lain(): void
    {
        [$pemilik, $tagihan] = $this->buatTagihan(1, 2026, 150000);

        $orangLain = Pelanggan::factory()->sudahAktif()->create();
        Sanctum::actingAs($orangLain);

        $this->postJson("/api/pelanggan/tagihan/{$tagihan->id}/bayar", [
            'jumlah_dibayar' => 150000,
        ])->assertStatus(403);
    }

    public function test_webhook_pembayaran_dengan_pakai_deposit_hanya_menyelesaikan_tagihan_terpilih(): void
    {
        [$pelanggan, $tagihan1] = $this->buatTagihan(1, 2026, 150000);
        $tagihan2 = Tagihan::factory()->create([
            'layanan_internet_id' => $tagihan1->layanan_internet_id,
            'periode_bulan' => 2,
            'periode_tahun' => 2026,
            'total_tagihan' => 100000,
            'status_pembayaran' => StatusPembayaranEnum::BELUM_BAYAR,
        ]);

        MutasiSaldoKredit::create([
            'pelanggan_id' => $pelanggan->id,
            'jenis' => 'kredit',
            'jumlah' => 100000,
            'keterangan' => 'Kelebihan pembayaran.',
        ]);

        $pembayaran = Pembayaran::factory()->create([
            'tagihan_id' => null,
            'pelanggan_id' => $pelanggan->id,
            'metode_pembayaran' => 'xendit',
            'provider' => 'xendit',
            'jumlah_dibayar' => 100000,
            'tagihan_terpilih' => [$tagihan1->id],
            'pakai_saldo_kredit' => true,
            'status' => StatusTransaksiEnum::PENDING,
        ]);

        $pembayaran->update([
            'provider_external_id' => 'PAY-' . $pembayaran->id,
        ]);

        $this->postJson('/api/webhook/xendit', [
            'external_id' => 'PAY-' . $pembayaran->id,
            'id' => 'xendit-inv-777',
            'status' => 'PAID',
            'amount' => 100000,
            'paid_amount' => 100000,
        ], [
            'X-Callback-Token' => 'test-webhook-token',
        ])->assertOk();

        $this->assertSame(
            StatusPembayaranEnum::SUDAH_BAYAR,
            $tagihan1->fresh()->status_pembayaran
        );

        $this->assertSame(
            StatusPembayaranEnum::BELUM_BAYAR,
            $tagihan2->fresh()->status_pembayaran
        );

        $this->assertEquals(
            50000,
            (float) MutasiSaldoKredit::where('pelanggan_id', $pelanggan->id)
                ->where('jenis', 'pemakaian')
                ->where('tagihan_id', $tagihan1->id)
                ->sum('jumlah')
        );

        $this->assertEquals(
            0,
            (float) MutasiSaldoKredit::where('pelanggan_id', $pelanggan->id)
                ->where('jenis', 'pemakaian')
                ->where('tagihan_id', $tagihan2->id)
                ->sum('jumlah')
        );

        $this->assertEquals(
            50000,
            (float) MutasiSaldoKredit::where('pelanggan_id', $pelanggan->id)
                ->where('jenis', 'pemakaian')
                ->where('tagihan_id', $tagihan1->id)
                ->sum('jumlah')
        );
    }
}