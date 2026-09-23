<?php

namespace App\Http\Middleware;

use App\Models\ShadowAktivitas;
use App\Models\ShadowSesi;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ShadowAktivitasTracer
{
    /**
     * Catat aksi mutasi yang dilakukan lewat token shadow.
     * Cukup berat per request → catat hanya method mutasi.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            return $response;
        }

        $token = $request->user()?->currentAccessToken();
        if (! $token || ! str_starts_with($token->name, 'shadow-')) {
            return $response;
        }

        $sesi = ShadowSesi::query()
            ->where('token_id', $token->id)
            ->whereNull('diakhiri_pada')
            ->first();

        if (! $sesi) {
            return $response;
        }

        ShadowAktivitas::create([
            'shadow_sesi_id' => $sesi->id,
            'admin_id' => $sesi->admin_id,
            'reseller_id' => $sesi->reseller_id,
            'method' => $request->method(),
            'path' => $request->path(),
            'ip' => $request->ip(),
        ]);

        return $response;
    }
}