<?php

namespace App\Http\Controllers\Api\Reseller;

use App\Http\Controllers\Api\Keuangan\PembayaranController;
use App\Models\Pelanggan;
use App\Models\Pembayaran;
use Illuminate\Http\Request;

/**
 * Riwayat pembayaran portal reseller — semua endpoint di-scope ke pelanggan
 * milik reseller yang login (pelanggan.reseller_id === admin.id). Data reseller
 * lain tidak pernah ikut.
 */
class ResellerPembayaranController extends PembayaranController
{
    private function scopeKeReseller(Request $request): void
    {
        $this->resellerId = $request->user()->id;
    }

    public function index(Request $request)
    {
        $this->scopeKeReseller($request);

        return parent::index($request);
    }

    public function show(Request $request, Pembayaran $pembayaran)
    {
        $this->scopeKeReseller($request);

        return parent::show($request, $pembayaran);
    }

    public function kredit(Request $request, Pelanggan $pelanggan)
    {
        $this->scopeKeReseller($request);

        return parent::kredit($request, $pelanggan);
    }

    public function laporanExcel(Request $request)
    {
        $this->scopeKeReseller($request);

        return parent::laporanExcel($request);
    }

    public function laporanPdf(Request $request)
    {
        $this->scopeKeReseller($request);

        return parent::laporanPdf($request);
    }
}