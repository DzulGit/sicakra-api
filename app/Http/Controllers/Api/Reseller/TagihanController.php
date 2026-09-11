<?php

namespace App\Http\Controllers\Api\Reseller;

use App\Enums\StatusLayananEnum;
use App\Enums\StatusPembayaranEnum;
use App\Enums\StatusTransaksiEnum;
use App\Events\PembayaranBerhasil;
use App\Http\Controllers\Controller;
use App\Models\Pelanggan;
use App\Models\Tagihan;
use App\Services\GenerateTagihanService;
use App\Services\SiklusPenagihanService;
use App\Services\XenditInvoiceService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TagihanController extends Controller
{
    public function __construct(
        private readonly GenerateTagihanService $generateTagihanService,
        private readonly SiklusPenagihanService $siklusPenagihanService,
        private readonly XenditInvoiceService $xenditInvoiceService,
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
            'jumlah_hari_jatuh_tempo' => 'sometimes|integer|min:1|max:31',
        ]);

        $layanan = $pelanggan->layananInternet()
            ->where('id', $validated['layanan_internet_id'])
            ->where('status', StatusLayananEnum::AKTIF)
            ->first();

        if (!$layanan) return response()->json(['message' => 'Layanan aktif tidak ditemukan.'], 422);
        if (Tagihan::where('layanan_internet_id', $layanan->id)->exists()) {
            return response()->json(['message' => 'Tagihan pertama sudah pernah dibuat.'], 422);
        }

        // Karena siklus tagihan dipatok tanggal 1 bulan depan, tanggal jatuh tempo bisa diarahkan ke akhir bulan ini atau tetap menggunakan offset hari.
        $jumlahHariJatuhTempo = (int) ($validated['jumlah_hari_jatuh_tempo'] ?? 7);
        $tanggalJatuhTempo = Carbon::today()->addDays($jumlahHariJatuhTempo);

        $tagihan = $this->generateTagihanService->generateTagihanPertama(
            $layanan,
            $validated['mode'],
            isset($validated['nominal_manual']) ? (float) $validated['nominal_manual'] : null,
            $tanggalJatuhTempo,
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

        if ($tagihan->status_pembayaran === StatusPembayaranEnum::SUDAH_BAYAR) {
            return response()->json(['message' => 'Tagihan sudah dibayar.'], 422);
        }

        $validated = $request->validate(['jumlah_bulan' => 'sometimes|integer|min:1|max:12']);
        $jumlahBulan = $validated['jumlah_bulan'] ?? $tagihan->jumlah_bulan;
        $admin = $request->user();

        $tagihanBaru = DB::transaction(function () use ($tagihan, $jumlahBulan, $admin) {
            $tagihan->update([
                'jumlah_bulan' => $jumlahBulan,
                'total_tagihan' => $tagihan->harga_snapshot * $jumlahBulan,
            ]);

            $pembayaran = $tagihan->pembayaran()->create([
                'metode_pembayaran' => 'tunai',
                'dibayar_oleh' => $admin->nama_lengkap, // Dicatat bahwa reseller yang menerima pembayaran tunai
                'jumlah_dibayar' => $tagihan->total_tagihan,
                'status' => StatusTransaksiEnum::BERHASIL,
                'dibayar_pada' => now(),
            ]);

            $tagihan->update([
                'status_pembayaran' => StatusPembayaranEnum::SUDAH_BAYAR,
                'dibayar_pada' => $pembayaran->dibayar_pada,
                'xendit_invoice_status' => 'paid',
            ]);

            $layanan = $tagihan->layananInternet;
            if ($layanan) {
                $layanan->update([
                    'tanggal_aktif' => $layanan->tanggal_aktif->copy()->addMonths($jumlahBulan),
                ]);
                $this->siklusPenagihanService->majukanJadwalSetelahPembayaran($tagihan);
            }

            PembayaranBerhasil::dispatch($tagihan, $pembayaran);

            return $tagihan;
        });

        return response()->json([
            'message' => 'Pembayaran tunai diterima. Tagihan lunas.',
            'data' => $tagihanBaru->fresh(['layananInternet.paketInternet', 'layananInternet.pelanggan', 'pembayaran']),
        ]);
    }

    public function perbaruiLink(Request $request, Tagihan $tagihan)
    {
        $this->pastikanMilikReseller($tagihan, $request);

        if ($tagihan->status_pembayaran === StatusPembayaranEnum::SUDAH_BAYAR) {
            return response()->json(['message' => 'Tagihan sudah dibayar.'], 422);
        }

        $tagihan->update([
            'status_pembayaran' => StatusPembayaranEnum::BELUM_BAYAR,
            'xendit_invoice_id' => null,
            'xendit_external_id' => null,
            'xendit_invoice_url' => null,
            'xendit_invoice_status' => 'expired',
            'xendit_invoice_expires_at' => null,
            'xendit_invoice_retry_count' => $tagihan->xendit_invoice_retry_count + 1,
        ]);

        $body = $this->xenditInvoiceService->buatInvoice($tagihan->fresh(), durasiHari: 7);

        $tagihan->update([
            'xendit_invoice_id' => $body['id'],
            'xendit_external_id' => $body['external_id'] ?? null,
            'xendit_invoice_url' => $body['invoice_url'],
            'xendit_invoice_status' => 'active',
            'xendit_invoice_expires_at' => $body['expiry_date'] ?? null,
        ]);

        return response()->json([
            'message' => 'Link pembayaran berhasil diperbarui.',
            'data' => $tagihan->fresh(['layananInternet.paketInternet', 'layananInternet.pelanggan', 'pembayaran']),
        ]);
    }
}