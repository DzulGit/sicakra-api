<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Http\Request;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            \App\Repositories\Contracts\PermohonanLayananRepositoryInterface::class,
            \App\Repositories\Eloquent\PermohonanLayananRepository::class
        );

        $this->app->bind(
            \App\Repositories\Contracts\LayananInternetRepositoryInterface::class,
            \App\Repositories\Eloquent\LayananInternetRepository::class
        );

        $this->app->bind(
            \App\Repositories\Contracts\TagihanRepositoryInterface::class,
            \App\Repositories\Eloquent\TagihanRepository::class
        );

        $this->app->bind(
            \App\Repositories\Contracts\JadwalKerjaRepositoryInterface::class,
            \App\Repositories\Eloquent\JadwalKerjaRepository::class
        );
        $this->app->bind(
            \App\Repositories\Contracts\TimTeknisiRepositoryInterface::class,
            \App\Repositories\Eloquent\TimTeknisiRepository::class
        );;

        $this->app->bind(
            \App\Repositories\Contracts\AdminRepositoryInterface::class,
            \App\Repositories\Eloquent\AdminRepository::class
        );

        $this->app->bind(
            \App\Repositories\Contracts\LaporanKendalaRepositoryInterface::class,
            \App\Repositories\Eloquent\LaporanKendalaRepository::class
        );
        $this->app->bind(
            \App\Repositories\Contracts\PelangganRepositoryInterface::class,
            \App\Repositories\Eloquent\PelangganRepository::class
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
    }
}
