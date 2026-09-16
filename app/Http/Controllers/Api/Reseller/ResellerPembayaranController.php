<?php

namespace App\Http\Controllers\Api\Reseller;

use App\Http\Controllers\Api\Keuangan\PembayaranController;
use App\Models\Pelanggan;
use Illuminate\Http\Request;

/**
 * Saldo kredit portal reseller — semua endpoint di-scope ke pelanggan
 * milik reseller yang login (pelanggan.reseller_id === admin.id). Data reseller
 * lain tidak pernah ikut.
 */
class ResellerPembayaranController extends PembayaranController
{
    private function scopeKeReseller(Request $request): void
    {
        $this->resellerId = $request->user()->id;
    }

    public function kredit(Request $request, Pelanggan $pelanggan)
    {
        $this->scopeKeReseller($request);

        return parent::kredit($request, $pelanggan);
    }
}