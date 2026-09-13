<?php

namespace App\Http\Controllers\Api\Reseller;

use App\Enums\StatusLayananEnum;
use App\Enums\StatusPembayaranEnum;
use App\Enums\StatusTransaksiEnum;
use App\Events\PembayaranBerhasil;
use App\Events\TagihanDibuat;
use App\Http\Controllers\Controller;
use App\Models\Pelanggan;
use App\Models\Tagihan;
use App\Models\Pembayaran;
use App\Services\GenerateTagihanService;
use App\Services\SiklusPenagihanService;
use App\Services\XenditInvoiceService;
use App\Services\PembayaranAllocationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TagihanController extends Controller
{
    public function __construct(
        private readonly GenerateTagihanService $generateTagihanService,
        private readonly SiklusPenagihanService $siklusPenagihanService,
        private readonly XenditInvoiceService $xenditInvoiceService,
        private readonly PembayaranAllocationService $pembayaranAllocationService,
    ) {}

    private function pastikanMilikReseller(Tagihan $tagihan, Request $request): void
    {
        $tagihan->loadMissing('layananInternet.pelanggan');
        if ($tagihan->layananInternet->pelanggan->reseller_id !== $request->user()->id) {
            abort(404, 'Tagihan tidak ditemukan.');
        }
    }

    public function index(Request $request)
    {
        $resellerId = $request->user()->id;

        $tagihan = Tagihan::whereHas('layananInternet.pelanggan', function ($query) use ($resellerId) {
            $query->where('reseller_id', $resellerId);
        })
        ->with(['layananInternet.paketInternet', 'layananInternet.pelanggan'])
        ->latest()
        ->paginate($request->integer('per_page', 10));

        return response()->json(['data' => $tagihan]);
    }

    public function show(Request $request, Tagihan $tagihan)
    {
        $this->pastikanMilikReseller($tagihan, $request);

        $tagihan->load(['layananInternet.paketInternet', 'layananInternet.pelanggan', 'pembayaran']);

        return response()->json(['data' => $tagihan]);
    }

    public function pendaftarBaru(Request $request)
    {
        $resellerId = $request->user()->id;
        $perPage = $request->integer('per_page', 10);

        $pelanggan = Pelanggan::query()
            ->where('reseller_id', $resellerId)

            // Harus memiliki minimal 1 layanan aktif
            ->whereHas('layananInternet', function ($query) {
                $query->where('status', StatusLayananEnum::AKTIF);
            })

            // Dan pelanggan BELUM PERNAH memiliki tagihan
            // dari layanan mana pun
            ->whereDoesntHave('layananInternet.tagihan')

            ->with([
                'layananInternet' => function ($query) {
                    $query
                        ->where('status', StatusLayananEnum::AKTIF)
                        ->with('paketInternet');
                },
            ])
            ->orderBy('nama_lengkap')
            ->paginate($perPage);

        return response()->json($pelanggan);
    }

    public function previewTagihanPertama(Request $request, Pelanggan $pelanggan)
    {
        if ($pelanggan->reseller_id !== $request->user()->id) abort(404);

        $layananAktif = $pelanggan->layananInternet()->where('status', StatusLayananEnum::AKTIF)->get();

        if ($layananAktif->isEmpty()) {
            return response()->json(['message' => 'Pelanggan tidak punya layanan aktif.'], 422);
        }

        $data = [];
        foreach ($layananAktif as $layanan) {
            if (Tagihan::where('layanan_internet_id', $layanan->id)->exists()) continue;

            $data[] = [
                'layanan_internet_id' => $layanan->id,
                'prorata' => $this->generateTagihanService->hitungTagihanPertama($layanan, 'prorata'),
                'full' => $this->generateTagihanService->hitungTagihanPertama($layanan, 'full'),
            ];
        }

        if (empty($data)) {
            return response()->json(['message' => 'Pelanggan ini sudah memiliki tagihan.'], 422);
        }

        return response()->json(['data' => $data]);
    }

    public function generateTagihanPertama(Request $request, Pelanggan $pelanggan)
    {
        if ($pelanggan->reseller_id !== $request->user()->id) abort(404);

        $validated = $request->validate([
            'layanan_internet_id' => 'required|integer',
            'mode' => 'required|string|in:prorata,full',
            'nominal_manual' => 'nullable|numeric|min:0',
        ]);

        $layanan = $pelanggan->layananInternet()
            ->where('id', $validated['layanan_internet_id'])
            ->where('status', StatusLayananEnum::AKTIF)
            ->first();

        if (!$layanan) return response()->json(['message' => 'Layanan aktif tidak ditemukan.'], 422);
        if (Tagihan::where('layanan_internet_id', $layanan->id)->exists()) {
            return response()->json(['message' => 'Tagihan pertama sudah pernah dibuat.'], 422);
        }

        $tagihan = $this->generateTagihanService->generateTagihanPertama(
            $layanan,
            $validated['mode'],
            isset($validated['nominal_manual']) ? (float) $validated['nominal_manual'] : null,
        );

        if (!$tagihan) return response()->json(['message' => 'Tagihan pertama gagal dibuat.'], 422);

        return response()->json([
            'message' => 'Tagihan pertama berhasil dibuat.',
            'data' => $tagihan->load(['layananInternet.paketInternet', 'layananInternet.pelanggan', 'pembayaran']),
        ], 201);
    }

    public function bayarTunai(Request $request, Tagihan $tagihan)
    {
        $this->pastikanMilikReseller($tagihan, $request);

        $tagihan->loadMissing('layananInternet.pelanggan');

        $pelanggan = $tagihan->layananInternet?->pelanggan;

        if (! $pelanggan) {
            return response()->json([
                'message' => 'Pelanggan tagihan tidak ditemukan.',
            ], 422);
        }

        $sisaTagihan = $this->hitungSisaTagihan($tagihan);

        if ($sisaTagihan <= 0) {
            return response()->json([
                'message' => 'Tagihan sudah lunas.',
            ], 422);
        }

        $validated = $request->validate([
            'jumlah_dibayar' => [
                'required',
                'numeric',
                'gt:0',
            ],
        ]);

        $jumlahDibayar = round(
            (float) $validated['jumlah_dibayar'],
            2
        );

        if ($jumlahDibayar <= 0) {
            return response()->json([
                'message' => 'Jumlah pembayaran harus lebih besar dari 0.',
            ], 422);
        }

        $admin = $request->user();

        $pembayaran = $this->pembayaranAllocationService
            ->buatPembayaranTunai(
                $pelanggan,
                $jumlahDibayar,
                [
                    'metode_pembayaran' => 'tunai',
                    'dibayar_oleh' => $admin->nama_lengkap,
                ]
            );

        PembayaranBerhasil::dispatch($pembayaran);

        return response()->json([
            'message' => 'Pembayaran tunai berhasil diproses.',
            'data' => $pembayaran->fresh([
                'pelanggan',
                'alokasiTagihan.tagihan.layananInternet.paketInternet',
                'mutasiSaldoKredit',
            ]),
        ]);
    }

    private function hitungSisaTagihan(Tagihan $tagihan): float { return $this->pembayaranAllocationService->hitungSisaTagihan($tagihan); }

    public function perbaruiLink(Request $request, Tagihan $tagihan)
    {
        $this->pastikanMilikReseller($tagihan, $request);

        $tagihan->loadMissing('layananInternet.pelanggan');

        $pelanggan = $tagihan->layananInternet?->pelanggan;

        if (! $pelanggan) {
            return response()->json([
                'message' => 'Pelanggan tagihan tidak ditemukan.',
            ], 422);
        }

        $sisaTagihan = $this->hitungSisaTagihan($tagihan);

        if ($sisaTagihan <= 0) {
            return response()->json([
                'message' => 'Tagihan sudah lunas.',
            ], 422);
        }

        $pembayaran = \App\Models\Pembayaran::create([
            'pelanggan_id' => $pelanggan->id,
            'tagihan_id' => null,
            'metode_pembayaran' => 'xendit',
            'provider' => 'xendit',
            'jumlah_dibayar' => $sisaTagihan,
            'status' => StatusTransaksiEnum::PENDING,
        ]);

        try {
            $body = $this->xenditInvoiceService->buatInvoice(
                $pembayaran,
                durasiHari: 7,
            );

            $pembayaran->update([
                'provider_reference' => $body['id'] ?? null,
                'provider_external_id' => $body['external_id'] ?? null,
                'payment_url' => $body['invoice_url'] ?? null,
                'provider_status' => $body['status'] ?? 'active',
                'provider_expires_at' => $body['expiry_date'] ?? null,
            ]);

            return response()->json([
                'message' => 'Link pembayaran berhasil diperbarui.',
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
     * List tagihan draft (belum_diterbitkan) untuk pelanggan milik reseller ini.
     * Hanya tampilkan pelanggan yang AKTIF dan pernah punya tagihan sebelumnya.
     */
    public function draftIndex(Request $request)
    {
        $resellerId = $request->user()->id;
        $perPage = $request->integer('per_page', 20);
        $periodeBulan = $request->integer('periode_bulan');
        $periodeTahun = $request->integer('periode_tahun');

        $query = Tagihan::draft()
            ->whereHas('layananInternet.pelanggan', function ($q) use ($resellerId) {
                $q->where('reseller_id', $resellerId);
            })
            ->whereHas('layananInternet', function ($q) {
                $q->where('status', StatusLayananEnum::AKTIF)
                    ->whereHas('pelanggan', function ($pq) {
                        // Validasi: pelanggan harus pernah punya tagihan sebelumnya
                        $pq->whereHas('layananInternet.tagihan', function ($tq) {
                            $tq->where('status_pembayaran', '!=', StatusPembayaranEnum::BELUM_DITERBITKAN);
                        });
                    });
            })
            ->with(['layananInternet.paketInternet', 'layananInternet.pelanggan']);

        if ($periodeBulan) {
            $query->where('periode_bulan', $periodeBulan);
        }
        if ($periodeTahun) {
            $query->where('periode_tahun', $periodeTahun);
        }

        $tagihan = $query->latest()->paginate($perPage);

        return response()->json(['data' => $tagihan]);
    }

    /**
     * Terbitkan tagihan draft untuk pelanggan milik reseller ini.
     */
    public function terbitkan(Request $request)
    {
        $resellerId = $request->user()->id;

        $validated = $request->validate([
            'tagihan_ids' => 'required|array|min:1',
            'tagihan_ids.*' => 'required|integer|exists:tagihan,id',
            'nominal' => 'nullable|array',
            'nominal.*' => 'required|numeric|min:0',
        ]);

        $tagihanIds = $validated['tagihan_ids'];
        $nominals = $validated['nominal'] ?? [];

        $berhasil = 0;
        $gagal = 0;
        $pesanGagal = [];

        DB::beginTransaction();

        try {
            foreach ($tagihanIds as $tagihanId) {
                $tagihan = Tagihan::find($tagihanId);

                if (! $tagihan || $tagihan->status_pembayaran !== StatusPembayaranEnum::BELUM_DITERBITKAN) {
                    $gagal++;
                    $pesanGagal[] = "Tagihan #{$tagihanId} tidak valid atau sudah diterbitkan.";
                    continue;
                }

                // Validasi ownership reseller
                $layanan = $tagihan->layananInternet;
                if (! $layanan || ! $layanan->pelanggan || $layanan->pelanggan->reseller_id !== $resellerId) {
                    $gagal++;
                    $pesanGagal[] = "Tagihan #{$tagihanId} bukan milik reseller ini.";
                    continue;
                }

                // Validasi: pelanggan harus pernah punya tagihan sebelumnya
                $sudahPunyaTagihan = Tagihan::where('layanan_internet_id', $layanan->id)
                    ->where('id', '!=', $tagihanId)
                    ->where('status_pembayaran', '!=', StatusPembayaranEnum::BELUM_DITERBITKAN)
                    ->exists();

                if (! $sudahPunyaTagihan) {
                    $gagal++;
                    $pesanGagal[] = "Pelanggan {$layanan->pelanggan->nama_lengkap} belum pernah punya tagihan sebelumnya.";
                    continue;
                }

                // Update nominal jika dikirim
                if (isset($nominals[$tagihanId])) {
                    $tagihan->update([
                        'total_tagihan' => $nominals[$tagihanId],
                    ]);
                }

                // Ubah status & dispatch event
                $tagihan->update([
                    'status_pembayaran' => StatusPembayaranEnum::BELUM_BAYAR,
                ]);

                TagihanDibuat::dispatch($tagihan);

                $berhasil++;
            }

            DB::commit();

            $pesan = "{$berhasil} tagihan berhasil diterbitkan.";
            if ($gagal > 0) {
                $pesan .= " {$gagal} gagal: " . implode('; ', $pesanGagal);
            }

            return response()->json([
                'message' => $pesan,
                'berhasil' => $berhasil,
                'gagal' => $gagal,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'Gagal menerbitkan tagihan: ' . $e->getMessage()], 500);
        }
    }
}