<?php

namespace App\Http\Controllers\Api\Keuangan;

use App\Http\Controllers\Controller;
use App\Models\MutasiSaldoKredit;
use App\Models\Pelanggan;
use App\Services\PembayaranAllocationService;
use Illuminate\Http\Request;

class PembayaranController extends Controller
{
    /** Scope reseller (null = admin keuangan melihat pelanggan non-reseller). */
    protected ?int $resellerId = null;

    public function __construct(
        private readonly PembayaranAllocationService $pembayaranAllocationService,
    ) {}

    /**
     * Saldo kredit / deposit pelanggan beserta ledger mutasinya
     * (MutasiSaldoKredit = sumber kebenaran, bukan kolom balance).
     */
    public function kredit(Request $request, Pelanggan $pelanggan)
    {
        $this->pastikanPelangganDiScope($pelanggan);

        $mutasi = $pelanggan
            ->mutasiSaldoKredit()
            ->with(['pembayaran', 'tagihan'])
            ->orderBy('created_at')
            ->get();

        $sisa = 0;
        $items = $mutasi->map(function (MutasiSaldoKredit $m) use (&$sisa) {
            $sisa = round($sisa + (float) $m->jumlah, 2);

            return [
                'id' => $m->id,
                'jenis' => $m->jenis,
                'jumlah' => round((float) $m->jumlah, 2),
                'keterangan' => $m->keterangan,
                'waktu_wib' => $this->pembayaranAllocationService->waktuWib($m->created_at),
                'nomor_pembayaran' => $m->pembayaran
                    ? $this->pembayaranAllocationService->nomorPembayaran($m->pembayaran)
                    : null,
                'nomor_tagihan' => $m->tagihan?->nomor_tagihan,
                'saldo_setelah' => round($sisa, 2),
            ];
        });

        return response()->json([
            'data' => [
                'pelanggan' => [
                    'id' => $pelanggan->id,
                    'nama_lengkap' => $pelanggan->nama_lengkap,
                    'nomor_pelanggan' => $pelanggan->nomor_pelanggan,
                ],
                'saldo_deposit' => round(
                    $this->pembayaranAllocationService->hitungSaldoKredit($pelanggan),
                    2
                ),
                'mutasi' => $items->reverse()->values(),
            ],
        ]);
    }

    protected function pastikanPelangganDiScope(Pelanggan $pelanggan): void
    {
        if (!$this->resellerId) {
            return;
        }

        if ($pelanggan->reseller_id !== $this->resellerId) {
            abort(404, 'Pelanggan tidak ditemukan.');
        }
    }
}