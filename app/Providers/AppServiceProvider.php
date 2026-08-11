<?php

namespace App\Providers;

use App\Events\PembayaranBerhasil;
use App\Events\TagihanDibuat;
use App\Listeners\BuatInvoiceXendit;
use App\Listeners\KirimNotifikasiPembayaranAdmin;
use App\Listeners\KirimNotifikasiTagihanDibuat;
use App\Listeners\KirimNotifikasiTagihanLunas;
use App\Repositories\Contracts\AdminRepositoryInterface;
use App\Repositories\Contracts\JadwalKerjaRepositoryInterface;
use App\Repositories\Contracts\LaporanKendalaRepositoryInterface;
use App\Repositories\Contracts\LayananInternetRepositoryInterface;
use App\Repositories\Contracts\PelangganRepositoryInterface;
use App\Repositories\Contracts\PermohonanLayananRepositoryInterface;
use App\Repositories\Contracts\TagihanRepositoryInterface;
use App\Repositories\Contracts\TimTeknisiRepositoryInterface;
use App\Repositories\Eloquent\AdminRepository;
use App\Repositories\Eloquent\JadwalKerjaRepository;
use App\Repositories\Eloquent\LaporanKendalaRepository;
use App\Repositories\Eloquent\LayananInternetRepository;
use App\Repositories\Eloquent\PelangganRepository;
use App\Repositories\Eloquent\PermohonanLayananRepository;
use App\Repositories\Eloquent\TagihanRepository;
use App\Repositories\Eloquent\TimTeknisiRepository;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            PermohonanLayananRepositoryInterface::class,
            PermohonanLayananRepository::class
        );

        $this->app->bind(
            LayananInternetRepositoryInterface::class,
            LayananInternetRepository::class
        );

        $this->app->bind(
            TagihanRepositoryInterface::class,
            TagihanRepository::class
        );

        $this->app->bind(
            JadwalKerjaRepositoryInterface::class,
            JadwalKerjaRepository::class
        );
        $this->app->bind(
            TimTeknisiRepositoryInterface::class,
            TimTeknisiRepository::class
        );

        $this->app->bind(
            AdminRepositoryInterface::class,
            AdminRepository::class
        );

        $this->app->bind(
            LaporanKendalaRepositoryInterface::class,
            LaporanKendalaRepository::class
        );
        $this->app->bind(
            PelangganRepositoryInterface::class,
            PelangganRepository::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        RateLimiter::for('login-pertama', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        RateLimiter::for('pendaftaran', function (Request $request) {
            return Limit::perMinute(3)->by($request->ip());
        });

        Event::listen(
            TagihanDibuat::class,
            BuatInvoiceXendit::class,
        );

        Event::listen(
            TagihanDibuat::class,
            KirimNotifikasiTagihanDibuat::class,
        );

        Event::listen(
            PembayaranBerhasil::class,
            KirimNotifikasiTagihanLunas::class,
        );

        Event::listen(
            PembayaranBerhasil::class,
            KirimNotifikasiPembayaranAdmin::class,
        );
    }
}
