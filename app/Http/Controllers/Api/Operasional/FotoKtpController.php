<?php

namespace App\Http\Controllers\Api\Operasional;

use App\Http\Controllers\Controller;
use App\Models\Pelanggan;
use App\Services\KtpStorageService;
use Symfony\Component\HttpFoundation\Response;

class FotoKtpController extends Controller
{
    /**
     * Preview foto KTP (admin internal).
     *
     * Authorization via middleware rute: auth:sanctum + tipe-pengguna:admin +
     * peran:operasional,keuangan,super_admin (sama seperti endpoint detail
     * pelanggan). Pelanggan milik reseller dilayani lewat
     * Admin\Operasional\ResellerController@fotoKtpPelanggan — konsisten dengan
     * scope data yang sudah ada (list/detail pelanggan internal mengecualikan
     * pelanggan reseller).
     */
    public function tampilkan(Pelanggan $pelanggan): Response
    {
        if ($pelanggan->reseller_id !== null) {
            abort(404);
        }

        return KtpStorageService::responGambar($pelanggan);
    }
}