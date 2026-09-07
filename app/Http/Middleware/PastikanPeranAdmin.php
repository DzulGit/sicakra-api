<?php

namespace App\Http\Middleware;

use App\Enums\PeranAdminEnum;
use App\Models\Admin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PastikanPeranAdmin
{
    /**
     * Proteksi rute berdasarkan `peran` admin. Dipasang SETELAH middleware
     * 'auth:sanctum' dan 'tipe-pengguna:admin'.
     *
     * Reseller mendapat akses gabungan ke modul operasional, teknisi, dan keuangan.
     *
     * Contoh: ->middleware('peran:keuangan')
     *         ->middleware('peran:operasional,super_admin')
     */
    public function handle(Request $request, Closure $next, string ...$peranDiizinkan): Response
    {
        $admin = $request->user();

        if (! $admin instanceof Admin) {
            abort(403, 'Akses tidak diizinkan.');
        }

        $peran = array_map(fn (string $p) => PeranAdminEnum::from($p), $peranDiizinkan);

        if (! $admin->memilikiPeran(...$peran)) {
            abort(403, 'Anda tidak memiliki izin untuk mengakses fitur ini.');
        }

        return $next($request);
    }
}