<?php

namespace App\Http\Controllers\Api\Pelanggan;

use App\Http\Controllers\Controller;
use App\Models\LaporanKendala;
use App\Models\LayananInternet;
use App\Models\PermohonanLayanan;
use App\Models\Tagihan;
use Illuminate\Http\Request;

class DashboardPelangganController extends Controller
{
    public function ringkasan(Request $request)
    {
        $pelangganId = $request->user()->id;

        $layanan = LayananInternet::where('pelanggan_id', $pelangganId)
            ->with('paketInternet')
            ->latest()
            ->get();

        $tagihan = Tagihan::whereHas('layananInternet', fn ($q) => $q->where('pelanggan_id', $pelangganId))
            ->with('layananInternet.paketInternet')
            ->latest()
            ->take(5)
            ->get();

        $kendala = LaporanKendala::whereHas('layananInternet', fn ($q) => $q->where('pelanggan_id', $pelangganId))
            ->whereIn('status', ['menunggu', 'diproses'])
            ->latest()
            ->take(5)
            ->get();

        $permohonanPending = PermohonanLayanan::where('pelanggan_id', $pelangganId)
            ->whereIn('status', ['MENUNGGU_VERIFIKASI', 'PERLU_REVISI', 'DITERIMA', 'DIJADWALKAN'])
            ->with('paketInternet')
            ->latest()
            ->take(5)
            ->get();

        $tagihanBelumBayar = $tagihan->filter(fn ($t) => $t->status_pembayaran->value === 'belum_bayar');
        $totalTagihanBelumBayar = $tagihanBelumBayar->sum(fn ($t) => (float) ($t->total_tagihan ?? 0));
        $kendalaAktif = $kendala->count();

        return response()->json([
            'data' => [
                'pelanggan' => [
                    'nama_lengkap' => $request->user()->nama_lengkap,
                    'nomor_pelanggan' => $request->user()->nomor_pelanggan,
                ],
                'ringkasan' => [
                    'total_layanan' => $layanan->count(),
                    'layanan_aktif' => $layanan->filter(fn ($l) => $l->status === 'aktif')->count(),
                    'tagihan_belum_bayar' => $tagihanBelumBayar->count(),
                    'total_tagihan_belum_bayar' => $totalTagihanBelumBayar,
                    'kendala_aktif' => $kendalaAktif,
                    'permohonan_pending' => $permohonanPending->count(),
                ],
                'layanan_terbaru' => $layanan->take(3)->map(fn ($l) => [
                    'id' => $l->id,
                    'nomor_layanan' => $l->nomor_layanan,
                    'nama_paket' => $l->paketInternet?->nama_paket ?? $l->nama_paket_custom,
                    'kecepatan_mbps' => $l->paketInternet?->kecepatan_mbps ?? $l->kecepatan_custom_mbps,
                    'status' => $l->status,
                    'alamat_pemasangan' => $l->alamat_pemasangan,
                    'masa_aktif_berakhir' => $l->masa_aktif_berakhir,
                ]),
                'tagihan_terbaru' => $tagihan->map(fn ($t) => [
                    'id' => $t->id,
                    'nomor_tagihan' => $t->nomor_tagihan,
                    'total' => (float) ($t->total_tagihan ?? 0),
                    'status_pembayaran' => $t->status_pembayaran->value,
                    'tenggat' => $t->tanggal_jatuh_tempo?->toDateString(),
                    'layanan' => $t->layananInternet?->paketInternet?->nama_paket ?? '-',
                ]),
                'kendala_terbaru' => $kendala->map(fn ($k) => [
                    'id' => $k->id,
                    'nomor_laporan' => $k->nomor_laporan,
                    'kategori_kendala' => $k->kategori_kendala,
                    'status' => $k->status,
                    'created_at' => $k->created_at->toIso8601String(),
                ]),
                'permohonan_terbaru' => $permohonanPending->map(fn ($p) => [
                    'id' => $p->id,
                    'nomor_permohonan' => $p->nomor_permohonan,
                    'jenis_permohonan' => $p->jenis_permohonan,
                    'status_permohonan' => $p->status,
                    'created_at' => $p->created_at->toIso8601String(),
                ]),
            ],
        ]);
    }
}
