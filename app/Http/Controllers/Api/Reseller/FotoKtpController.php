<?php

namespace App\Http\Controllers\Api\Reseller;

use App\Http\Controllers\Controller;
use App\Models\Pelanggan;
use App\Services\KtpStorageService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class FotoKtpController extends Controller
{
    /**
     * Preview foto KTP (reseller).
     *
     * Reseller hanya boleh melihat pelanggan dalam scope-nya sendiri
     * (reseller_id === id admin yang login). Pelanggan milik reseller lain atau
     * pelanggan internal → 404 (tidak membocorkan eksistensi), pola sama seperti
     * ResellerPortalController@pelangganShow.
     */
    public function tampilkan(Request $request, Pelanggan $pelanggan): Response
    {
        if ($pelanggan->reseller_id !== $request->user()->id) {
            abort(404);
        }

        return KtpStorageService::responGambar($pelanggan);
    }
}