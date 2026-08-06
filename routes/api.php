<?php

use App\Http\Controllers\Api\Auth\AuthAdminController;
use App\Http\Controllers\Api\Auth\AuthPelangganController;
use App\Http\Controllers\Api\Keuangan\DashboardKeuanganController;
use App\Http\Controllers\Api\Keuangan\PendapatanController;
use App\Http\Controllers\Api\Keuangan\TagihanController as KeuanganTagihanController;
use App\Http\Controllers\Api\Operasional\DashboardController;
use App\Http\Controllers\Api\Operasional\LaporanKendalaController as OperasionalLaporanKendalaController;
use App\Http\Controllers\Api\Operasional\PaketInternetController as OperasionalPaketInternetController;
use App\Http\Controllers\Api\Operasional\PelangganController;
use App\Http\Controllers\Api\Operasional\PermohonanLayananController;
use App\Http\Controllers\Api\Pelanggan\DashboardPelangganController;
use App\Http\Controllers\Api\Pelanggan\LaporanKendalaSayaController;
use App\Http\Controllers\Api\Pelanggan\LayananSayaController;
use App\Http\Controllers\Api\Pelanggan\PermohonanSayaController;
use App\Http\Controllers\Api\Pelanggan\ProfilController;
use App\Http\Controllers\Api\Pelanggan\TagihanSayaController;
use App\Http\Controllers\Api\Pendaftaran\PendaftaranController;
use App\Http\Controllers\Api\Publik\PaketInternetController as PublikPaketInternetController;
use App\Http\Controllers\Api\SuperAdmin\AdminController;
use App\Http\Controllers\Api\SuperAdmin\TimTeknisiController;
use App\Http\Controllers\Api\Teknisi\DashboardTeknisiController;
use App\Http\Controllers\Api\Teknisi\JadwalKerjaController;
use App\Http\Controllers\Api\Teknisi\LaporanKendalaController as TeknisiLaporanKendalaController;
use Illuminate\Support\Facades\Route;

// ===== PUBLIK (tanpa login) =====
Route::post('pendaftaran', [PendaftaranController::class, 'store'])
    ->middleware('throttle:pendaftaran');
Route::get('paket-internet', [PublikPaketInternetController::class, 'index']);

// ===== ADMIN =====
Route::prefix('admin')->group(function () {
    Route::post('login', [AuthAdminController::class, 'login'])
        ->middleware('throttle:login');

    Route::middleware(['auth:sanctum', 'tipe-pengguna:admin'])->group(function () {
        Route::post('logout', [AuthAdminController::class, 'logout']);

        // ----- Operasional -----
        Route::middleware('peran:operasional,super_admin')->prefix('operasional')->group(function () {
            Route::get('dashboard', [DashboardController::class, 'index']);
            Route::get('permohonan-layanan', [PermohonanLayananController::class, 'index']);
            Route::get('permohonan-layanan/{permohonan}', [PermohonanLayananController::class, 'show']);
            Route::post('permohonan-layanan', [PermohonanLayananController::class, 'store']);
            Route::patch('permohonan-layanan/{permohonan}/verifikasi', [PermohonanLayananController::class, 'verifikasi']);
            Route::post('permohonan-layanan/{permohonan}/verifikasi-dan-jadwalkan', [PermohonanLayananController::class, 'verifikasiDanJadwalkan']);
            Route::post('permohonan-layanan/{permohonan}/jadwalkan-kerja', [PermohonanLayananController::class, 'jadwalkanKerja']);

            Route::get('laporan-kendala', [OperasionalLaporanKendalaController::class, 'index']);
            Route::get('laporan-kendala/{laporanKendala}', [OperasionalLaporanKendalaController::class, 'show']);
            Route::patch('laporan-kendala/{laporanKendala}/terima', [OperasionalLaporanKendalaController::class, 'terima']);
            Route::patch('laporan-kendala/{laporanKendala}/teruskan-ke-teknisi', [OperasionalLaporanKendalaController::class, 'teruskanKeTeknisi']);
            Route::patch('laporan-kendala/{laporanKendala}/tutup', [OperasionalLaporanKendalaController::class, 'tutup']);

            Route::get('teknisi', [PermohonanLayananController::class, 'daftarTeknisi']);

            Route::get('tim-teknisi', [TimTeknisiController::class, 'index']);
            Route::get('tim-teknisi/aktif', [TimTeknisiController::class, 'listAktif']);
            Route::get('tim-teknisi/{timTeknisi}', [TimTeknisiController::class, 'show']);
            Route::post('tim-teknisi', [TimTeknisiController::class, 'store']);
            Route::patch('tim-teknisi/{timTeknisi}', [TimTeknisiController::class, 'update']);

            Route::get('paket-internet', [OperasionalPaketInternetController::class, 'index']);
            Route::get('paket-internet/{paketInternet}', [OperasionalPaketInternetController::class, 'show']);
            Route::post('paket-internet', [OperasionalPaketInternetController::class, 'store']);
            Route::patch('paket-internet/{paketInternet}', [OperasionalPaketInternetController::class, 'update']);
            Route::delete('paket-internet/{paketInternet}', [OperasionalPaketInternetController::class, 'destroy']);
        });

        // ----- Pelanggan (Operasional + Keuangan) -----
        // Keuangan perlu buka detail pelanggan untuk generate tagihan manual.
        Route::middleware('peran:operasional,keuangan,super_admin')->prefix('operasional')->group(function () {
            Route::get('pelanggan', [PelangganController::class, 'index']);
            Route::get('pelanggan/{pelanggan}', [PelangganController::class, 'show']);
        });

        // ----- Teknisi -----
        Route::middleware('peran:teknisi,super_admin')->prefix('teknisi')->group(function () {
            Route::get('dashboard', [DashboardTeknisiController::class, 'index']);

            Route::get('jadwal-kerja', [JadwalKerjaController::class, 'index']);
            Route::get('jadwal-kerja/{jadwalKerja}', [JadwalKerjaController::class, 'show']);
            Route::patch('jadwal-kerja/{jadwalKerja}/hasil', [JadwalKerjaController::class, 'isiHasil']);

            Route::get('laporan-kendala', [TeknisiLaporanKendalaController::class, 'index']);
            Route::get('laporan-kendala/{laporanKendala}', [TeknisiLaporanKendalaController::class, 'show']);
            Route::patch('laporan-kendala/{laporanKendala}/selesaikan', [TeknisiLaporanKendalaController::class, 'selesaikan']);
        });

        // ----- Keuangan -----
        Route::middleware('peran:keuangan,super_admin')->prefix('keuangan')->group(function () {
            Route::get('dashboard', [DashboardKeuanganController::class, 'index']);
            Route::get('pendapatan', [PendapatanController::class, 'index']);
            Route::get('pendapatan/report/excel', [PendapatanController::class, 'reportExcel']);
            Route::get('pendapatan/report', [PendapatanController::class, 'report']);
            Route::get('tagihan-ringkasan', [KeuanganTagihanController::class, 'ringkasanOmzet']);
            Route::get('tagihan', [KeuanganTagihanController::class, 'index']);
            Route::get('tagihan/{tagihan}', [KeuanganTagihanController::class, 'show']);
            Route::post('tagihan/generate/{pelanggan}', [KeuanganTagihanController::class, 'generateUntukPelanggan']);
            Route::post('tagihan/{tagihan}/regenerate', [KeuanganTagihanController::class, 'regenerate']);
        });

        // ----- Super Admin -----
        Route::middleware('peran:super_admin')->prefix('super-admin')->group(function () {
            Route::get('admin', [AdminController::class, 'index']);
            Route::get('admin/{admin}', [AdminController::class, 'show']);
            Route::post('admin', [AdminController::class, 'store']);
            Route::patch('admin/{admin}', [AdminController::class, 'update']);
            Route::patch('admin/{admin}/nonaktifkan', [AdminController::class, 'nonaktifkan']);

        });
    });
});

// ===== PELANGGAN =====
Route::prefix('pelanggan')->group(function () {
    Route::post('login', [AuthPelangganController::class, 'login'])
        ->name('login')
        ->middleware('throttle:login');

    Route::middleware(['auth:sanctum', 'tipe-pengguna:pelanggan'])->group(function () {
        Route::post('logout', [AuthPelangganController::class, 'logout']);

        Route::get('dashboard/ringkasan', [DashboardPelangganController::class, 'ringkasan']);

        Route::get('profil', [ProfilController::class, 'show']);
        Route::patch('profil', [ProfilController::class, 'update']);
        Route::patch('profil/username', [ProfilController::class, 'ubahUsername']);
        Route::patch('profil/password', [ProfilController::class, 'ubahPassword']);

        Route::get('layanan', [LayananSayaController::class, 'index']);
        Route::get('layanan/{layanan}', [LayananSayaController::class, 'show']);

        Route::get('tagihan', [TagihanSayaController::class, 'index']);
        Route::get('tagihan/{tagihan}', [TagihanSayaController::class, 'show']);
        Route::post('tagihan/{tagihan}/bayar', [TagihanSayaController::class, 'bayar']);
        Route::post('tagihan/{tagihan}/regenerate-invoice', [TagihanSayaController::class, 'regenerateInvoice']);

        Route::get('laporan-kendala', [LaporanKendalaSayaController::class, 'index']);
        Route::get('laporan-kendala/{laporanKendala}', [LaporanKendalaSayaController::class, 'show']);
        Route::post('laporan-kendala', [LaporanKendalaSayaController::class, 'store']);

        Route::post('permohonan-layanan', [PermohonanSayaController::class, 'store']);
    });
});
