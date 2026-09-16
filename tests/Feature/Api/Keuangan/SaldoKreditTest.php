<?php

namespace Tests\Feature\Api\Keuangan;

use App\Enums\StatusLayananEnum;
use App\Enums\StatusPembayaranEnum;
use App\Enums\StatusTransaksiEnum;
use App\Models\Admin;
use App\Models\LayananInternet;
use App\Models\Pelanggan;
use App\Models\Pembayaran;
use App\Models\Tagihan;
use App\Services\PembayaranAllocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SaldoKreditTest extends TestCase
{
    use RefreshDatabase;

    private function buatPelangganDanTagihan(array $atte = []): array
    {
        $pelanggan = $atte['pelanggan_model'] ?? Pelanggan::factory()->create($atte['pelanggan'] ?? []);
        $layanan = LayananInternet::factory()->create([
            'pelanggan_id' => $pelanggan->id,
            'status' => StatusLayananEnum::AKTIF,
        ]);

        $tagihan = Tagihan::factory()->create(array_merge([
            'layanan_internet_id' => $layanan->id,
            'periode_bulan' => 9,
            'periode_tahun' => 2026,
            'harga_snapshot' => 150000,
            'total_tagihan' => 150000,
            'status_pembayaran' => StatusPembayaranEnum::BELUM_BAYAR,
        ], $atte['tagihan'] ?? []));

        return [$pelanggan, $tagihan];
    }

    public function test_kelebihan_pembayaran_menjadi_kredit_dan_muncul_di_saldo_kredit(): void
    {
        $admin = Admin::factory()->keuangan()->create();
        Sanctum::actingAs($admin);

        [$pelanggan, $tagihan] = $this->buatPelangganDanTagihan();
        $service = app(PembayaranAllocationService::class);
        $service->buatPembayaranTunai($pelanggan, 200000, ['dibayar_oleh' => 'Admin']);

        $this->getJson("/api/admin/keuangan/saldo-kredit/{$pelanggan->id}")
            ->assertOk()
            ->assertJsonPath('data.saldo_deposit', 50000)
            ->assertJsonCount(1, 'data.mutasi')
            ->assertJsonPath('data.mutasi.0.jenis', 'kredit')
            ->assertJsonPath('data.mutasi.0.saldo_setelah', 50000);
    }

    public function test_pemakaian_kredit_mengurangi_saldo_dan_masuk_timeline_tagihan(): void
    {
        $admin = Admin::factory()->keuangan()->create();
        Sanctum::actingAs($admin);

        [$pelanggan, $tagihanA] = $this->buatPelangganDanTagihan();
        $service = app(PembayaranAllocationService::class);

        // 200.000 -> lunasi tagihanA (150.000) + 50.000 tersimpan sebagai kredit.
        $service->buatPembayaranTunai($pelanggan, 200000, ['dibayar_oleh' => 'Admin']);
        $this->assertSame(50000.0, (float) $service->hitungSaldoKredit($pelanggan));

        // Tagihan baru terbit; pemakaian kredit menutup sebagiannya.
        [, $tagihanB] = $this->buatPelangganDanTagihan([
            'pelanggan_model' => $pelanggan,
        ]);
        $service->gunakanSaldoKredit($pelanggan);

        $this->getJson("/api/admin/keuangan/saldo-kredit/{$pelanggan->id}")
            ->assertOk()
            ->assertJsonPath('data.saldo_deposit', 0)
            ->assertJsonCount(2, 'data.mutasi');

        $timeline = $this->getJson("/api/admin/keuangan/tagihan/{$tagihanB->id}")->json('data.timeline_pembayaran');
        $kreditEvent = collect($timeline)->first(fn ($item) => $item['jenis'] === 'kredit');
        $this->assertNotNull($kreditEvent, 'Pemakaian kredit masuk timeline tagihan');
        $this->assertSame(50000, $kreditEvent['jumlah']);
        $this->assertSame(100000, $kreditEvent['sisa_setelah']);
    }

    public function test_reseller_hanya_melihat_saldo_kredit_pelanggan_sendiri(): void
    {
        $resellerA = Admin::factory()->reseller()->create();
        $resellerB = Admin::factory()->reseller()->create();

        [$pelangganA] = $this->buatPelangganDanTagihan(['pelanggan' => ['reseller_id' => $resellerA->id]]);
        [$pelangganB] = $this->buatPelangganDanTagihan(['pelanggan' => ['reseller_id' => $resellerB->id]]);

        $service = app(PembayaranAllocationService::class);
        $service->buatPembayaranTunai($pelangganA, 150000, ['dibayar_oleh' => 'Admin']);
        $service->buatPembayaranTunai($pelangganB, 150000, ['dibayar_oleh' => 'Admin']);

        Sanctum::actingAs($resellerA);

        $this->getJson("/api/reseller/saldo-kredit/{$pelangganA->id}")
            ->assertOk()
            ->assertJsonPath('data.saldo_deposit', 0);

        // Saldo kredit pelanggan reseller lain ditolak.
        $this->getJson("/api/reseller/saldo-kredit/{$pelangganB->id}")
            ->assertNotFound();
    }
}