<?php

namespace App\Http\Controllers\Api\Pelanggan;

use App\Enums\StatusPembayaranEnum;
use App\Enums\StatusTransaksiEnum;
use App\Http\Controllers\Controller;
use App\Models\Pembayaran;
use App\Models\Tagihan;
use App\Repositories\Contracts\TagihanRepositoryInterface;
use App\Services\XenditInvoiceService;
use App\Services\PembayaranAllocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TagihanSayaController extends Controller
{
    public function __construct(
        private readonly TagihanRepositoryInterface $tagihanRepository,
        private readonly XenditInvoiceService $xenditInvoiceService,
        private readonly PembayaranAllocationService $pembayaranAllocationService,
    ) {}

    public function index(Request $request, \App\Filters\TagihanFilter $filter)
    {
        $this->authorize('viewAny', Tagihan::class);

        $data = $this->tagihanRepository->paginateUntukPelanggan(
            $request->user()->id,
            $filter
        );

        $data->getCollection()->transform(function (Tagihan $item) {
            $this->lengkapiDetailPembayaran($item);

            return $item;
        });

        return response()->json([
            'data' => $data,
        ]);
    }

    public function show(Tagihan $tagihan)
    {
        $this->authorize('view', $tagihan);

        $tagihan = $this->tagihanRepository->find(
            $tagihan->id,
            [
                'layananInternet.paketInternet',
                'layananInternet.pelanggan',
                'pembayaran',
                'alokasiPembayaran.pembayaran',
            ],
        );

        $this->lengkapiDetailPembayaran($tagihan);
        $this->lengkapiRiwayatPembayaran($tagihan);

        return response()->json([
            'data' => $tagihan,
        ]);
    }

    /**
     * Membuat transaksi pembayaran baru dan invoice Xendit.
     *
     * Jika jumlah_dibayar tidak dikirim, invoice dibuat sebesar
     * sisa tagihan.
     *
     * Pembayaran belum dianggap berhasil di sini.
     * Status akan menjadi PENDING dan baru diselesaikan oleh webhook
     * setelah Xendit mengonfirmasi pembayaran.
     */
    public function bayar(
        Request $request,
        Tagihan $tagihan
    ): JsonResponse {
        $this->authorize('view', $tagihan);

        $tagihan->loadMissing([
            'layananInternet.pelanggan',
            'alokasiPembayaran.pembayaran',
        ]);

        $sisaTagihan = $this->hitungSisaTagihan($tagihan);

        if ($sisaTagihan <= 0) {
            return response()->json([
                'message' => 'Tagihan sudah lunas.',
            ], 422);
        }

        $validated = $request->validate([
            'jumlah_dibayar' => [
                'sometimes',
                'numeric',
                'min:1',
            ],
            'gunakan_deposit' => [
                'sometimes',
                'boolean',
            ],
        ]);

        $jumlahDibayar = isset($validated['jumlah_dibayar'])
            ? round((float) $validated['jumlah_dibayar'], 2)
            : $sisaTagihan;

        if ($jumlahDibayar <= 0) {
            return response()->json([
                'message' => 'Jumlah pembayaran harus lebih besar dari 0.',
            ], 422);
        }

        $pelanggan = $tagihan->layananInternet?->pelanggan;

        if (!$pelanggan) {
            return response()->json([
                'message' => 'Pelanggan tagihan tidak ditemukan.',
            ], 422);
        }

        /*
         * Pembayaran per-tagihan hanya boleh menyelesaikan tagihan itu.
         * Kelebihannya otomatis menjadi saldo kredit saat webhook berhasil.
         */
        $terpilih = [$tagihan->id];

        $pembayaran = DB::transaction(function () use (
            $pelanggan,
            $jumlahDibayar,
            $terpilih,
            $validated
        ) {
            return Pembayaran::create([
                'pelanggan_id' => $pelanggan->id,
                'tagihan_id' => null,
                'metode_pembayaran' => 'xendit',
                'provider' => 'xendit',
                'jumlah_dibayar' => $jumlahDibayar,
                'tagihan_terpilih' => $terpilih,
                'pakai_saldo_kredit' => (bool) ($validated['gunakan_deposit'] ?? false),
                'status' => StatusTransaksiEnum::PENDING,
            ]);
        });

        try {
            $body = $this->xenditInvoiceService->buatInvoice(
                $pembayaran
            );

            $pembayaran->update([
                'provider' => 'xendit',
                'provider_reference' =>
                    $body['id'] ?? null,

                'provider_external_id' =>
                    $body['external_id'] ?? null,
                'payment_url' => $body['invoice_url'] ?? null,
                'provider_status' => 'active',
                'provider_expires_at' =>
                    $body['expiry_date'] ?? null,
            ]);

            return response()->json([
                'message' => 'Invoice pembayaran berhasil dibuat.',
                'data' => $pembayaran->fresh([
                    'pelanggan',
                    'alokasiTagihan.tagihan',
                ]),
            ]);
        } catch (\Throwable $e) {
            $pembayaran->update([
                'status' => StatusTransaksiEnum::GAGAL,
                'provider_status' => 'failed',
            ]);

            throw $e;
        }
    }

    public function bayarGabungan(
        Request $request,
    ): JsonResponse {
        $pelanggan = $request->user();

        $validated = $request->validate([
            'jumlah_dibayar' => [
                'required',
                'numeric',
                'min:1',
            ],
            'tagihan_ids' => [
                'sometimes',
                'array',
                'min:1',
            ],
            'tagihan_ids.*' => ['integer'],
            'gunakan_deposit' => [
                'sometimes',
                'boolean',
            ],
        ]);

        $jumlahDibayar = round(
            (float) $validated['jumlah_dibayar'],
            2
        );

        $tagihan = Tagihan::query()
            ->whereHas(
                'layananInternet',
                fn ($query) => $query->where(
                    'pelanggan_id',
                    $pelanggan->id
                )
            )
            ->where(
                'status_pembayaran',
                StatusPembayaranEnum::BELUM_BAYAR->value
            );

        if (!empty($validated['tagihan_ids'])) {
            $tagihan->whereIn(
                'id',
                array_map('intval', $validated['tagihan_ids'])
            );
        }

        $tagihan = $tagihan
            ->orderBy('periode_tahun')
            ->orderBy('periode_bulan')
            ->orderBy('id')
            ->get();

        if ($tagihan->isEmpty()) {
            return response()->json([
                'message' => 'Tidak ada tagihan yang perlu dibayar.',
            ], 422);
        }

        $totalSisa = $tagihan->sum(
            fn (Tagihan $itemTagihan) =>
                $this->pembayaranAllocationService
                    ->hitungSisaTagihan($itemTagihan)
        );

        $totalSisa = round($totalSisa, 2);

        if ($totalSisa <= 0) {
            return response()->json([
                'message' => 'Semua tagihan sudah lunas.',
            ], 422);
        }

        $terpilih = $tagihan
            ->pluck('id')
            ->map(fn (int $id) => (int) $id)
            ->all();

        /*
        * Pembayaran boleh melebihi total tagihan.
        * Kelebihannya nanti otomatis menjadi saldo kredit
        * ketika webhook Xendit berhasil.
        */
        $pembayaran = Pembayaran::create([
            'pelanggan_id' => $pelanggan->id,
            'tagihan_id' => null,
            'metode_pembayaran' => 'xendit',
            'provider' => 'xendit',
            'jumlah_dibayar' => $jumlahDibayar,
            'tagihan_terpilih' => $terpilih,
            'pakai_saldo_kredit' => (bool) ($validated['gunakan_deposit'] ?? false),
            'status' => StatusTransaksiEnum::PENDING,
            'provider_status' => 'pending',
        ]);

        try {
            $body = $this->xenditInvoiceService->buatInvoice(
                $pembayaran,
                durasiHari: 7
            );

            $pembayaran->update([
                'provider' => 'xendit',
                'provider_reference' => $body['id'] ?? null,
                'provider_external_id' => $body['external_id'] ?? null,
                'payment_url' => $body['invoice_url'] ?? null,
                'provider_status' => 'active',
                'provider_expires_at' => $body['expiry_date'] ?? null,
                'referensi_xendit' => $body['external_id'] ?? null,
            ]);

            return response()->json([
                'message' => 'Invoice pembayaran gabungan berhasil dibuat.',
                'data' => [
                    'pembayaran' => $pembayaran->fresh(),
                    'total_tagihan' => $totalSisa,
                    'jumlah_dibayar' => $jumlahDibayar,
                    'kelebihan' => max(
                        0,
                        round($jumlahDibayar - $totalSisa, 2)
                    ),
                    'payment_url' => $body['invoice_url'] ?? null,
                ],
            ], 201);
        } catch (\Throwable $e) {
            $pembayaran->update([
                'status' => StatusTransaksiEnum::GAGAL,
                'provider_status' => 'failed',
            ]);

            throw $e;
        }
    }

    /**
     * Saldo deposit pelanggan + riwayat mutasi terakhir.
     */
    public function deposit(Request $request): JsonResponse
    {
        $pelanggan = $request->user();

        return response()->json([
            'data' => [
                'saldo_deposit' => round(
                    $this->pembayaranAllocationService
                        ->hitungSaldoKredit($pelanggan),
                    2
                ),
                'mutasi' => $pelanggan
                    ->mutasiSaldoKredit()
                    ->latest('id')
                    ->limit(20)
                    ->get(),
            ],
        ]);
    }

    /**
     * Ringkasan tunggakan pelanggan berdasarkan sisa tagihan.
     */
    public function tunggakan(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->pembayaranAllocationService
                ->ringkasanTunggakan($request->user()),
        ]);
    }

    /**
     * Menggunakan saldo deposit untuk melunasi tagihan yang
     * menunggak (dari periode tertua).
     */
    public function gunakanDeposit(Request $request): JsonResponse
    {
        $pelanggan = $request->user();

        $hasil = $this->pembayaranAllocationService
            ->gunakanSaldoKredit($pelanggan);

        return response()->json([
            'message' => 'Saldo deposit berhasil digunakan.',
            'data' => [
                ...$hasil,
                'saldo_deposit' => round(
                    $this->pembayaranAllocationService
                        ->hitungSaldoKredit($pelanggan),
                    2
                ),
                'tunggakan' => $this->pembayaranAllocationService
                    ->ringkasanTunggakan($pelanggan),
            ],
        ]);
    }

    /**
     * Membuat invoice Xendit baru untuk tagihan yang belum lunas.
     *
     * Setiap regenerate menghasilkan Pembayaran baru.
     * Pembayaran lama tidak dihapus sehingga histori transaksi tetap utuh.
     */
    public function regenerateInvoice(
        Tagihan $tagihan
    ): JsonResponse {
        $this->authorize('view', $tagihan);

        $tagihan->loadMissing([
            'layananInternet.pelanggan',
            'alokasiPembayaran.pembayaran',
        ]);

        $sisaTagihan = $this->hitungSisaTagihan($tagihan);

        if ($sisaTagihan <= 0) {
            return response()->json([
                'message' => 'Tagihan sudah lunas.',
            ], 422);
        }

        $pelanggan = $tagihan->layananInternet?->pelanggan;

        if (!$pelanggan) {
            return response()->json([
                'message' => 'Pelanggan tagihan tidak ditemukan.',
            ], 422);
        }

        $pembayaran = Pembayaran::create([
            'pelanggan_id' => $pelanggan->id,
            'tagihan_id' => null,
            'metode_pembayaran' => 'xendit',
            'provider' => 'xendit',
            'jumlah_dibayar' => $sisaTagihan,
            'tagihan_terpilih' => [(int) $tagihan->id],
            'status' => StatusTransaksiEnum::PENDING,
        ]);

        try {
            $body = $this->xenditInvoiceService->buatInvoice(
                $pembayaran,
                durasiHari: 7
            );

            $pembayaran->update([
                'provider' => 'xendit',
                'provider_reference' => $body['id'] ?? null,
                'provider_external_id' =>
                    $body['external_id'] ?? null,
                'payment_url' => $body['invoice_url'] ?? null,
                'provider_status' => 'active',
                'provider_expires_at' =>
                    $body['expiry_date'] ?? null,
            ]);

            return response()->json([
                'message' => 'Link pembayaran baru berhasil dibuat.',
                'data' => $pembayaran->fresh([
                    'pelanggan',
                    'alokasiTagihan.tagihan',
                ]),
            ]);
        } catch (\Throwable $e) {
            $pembayaran->update([
                'status' => StatusTransaksiEnum::GAGAL,
                'provider_status' => 'failed',
            ]);

            throw $e;
        }
    }

    /**
     * Menghitung sisa sebuah tagihan berdasarkan seluruh
     * pembayaran yang sudah berhasil dialokasikan.
     */
    private function hitungSisaTagihan(Tagihan $tagihan): float
    {
        return $this->pembayaranAllocationService->hitungSisaTagihan($tagihan);
    }

    /**
     * Menambahkan sisa_tagihan, sudah_dibayar, dan
     * saldo_kredit_digunakan ke response tagihan.
     */
    private function lengkapiDetailPembayaran(Tagihan $tagihan): void
    {
        $detail = $this->pembayaranAllocationService
            ->detailTagihan($tagihan);

        foreach (['sudah_dibayar', 'saldo_kredit_digunakan', 'sisa_tagihan'] as $key) {
            $tagihan->setAttribute($key, $detail[$key]);
        }
    }

    /**
     * Menambahkan riwayat pembayaran berbasis alokasi
     * (Pembayaran.tagihan_id kini nullable).
     */
    private function lengkapiRiwayatPembayaran(Tagihan $tagihan): void
    {
        if ($tagihan->relationLoaded('alokasiPembayaran')) {
            $riwayat = $tagihan->alokasiPembayaran
                ->sortByDesc('id')
                ->map->pembayaran
                ->filter()
                ->values()
                ->all();
        } else {
            $riwayat = $this->pembayaranAllocationService
                ->riwayatPembayaranTagihan($tagihan);
        }

        $tagihan->setAttribute('riwayat_pembayaran', $riwayat);
    }
}