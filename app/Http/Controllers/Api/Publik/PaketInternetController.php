<?php

namespace App\Http\Controllers\Api\Publik;

use App\Http\Controllers\Controller;
use App\Models\PaketInternet;

class PaketInternetController extends Controller
{
    /**
     * Endpoint PUBLIK — dipakai halaman "Paket Internet" & form pendaftaran
     * di Landing Page. Hanya paket yang status_aktif = true yang ditampilkan.
     */
    public function index()
    {
        $paket = PaketInternet::whereNull('reseller_id')
            ->where('status_aktif', true)
            ->orderBy('kecepatan_mbps')
            ->get();

        return response()->json(['data' => $paket]);
    }
}