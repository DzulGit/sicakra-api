<?php

namespace App\Http\Controllers\Api\Reseller;

use App\Enums\PeranAdminEnum;
use App\Http\Controllers\Controller;
use App\Models\ShadowSesi;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class ShadowSesiController extends Controller
{
    /**
     * Tukar kode sekali pakai menjadi token shadow berumur pendek.
     * Kode hanya dipakai admin yang membuka tab portal reseller.
     */
    public function klaim(Request $request)
    {
        $kode = $request->input('kode');

        if (! is_string($kode) || strlen($kode) > 128) {
            throw ValidationException::withMessages([
                'kode' => ['Kode shadow tidak valid.'],
            ]);
        }

        $sesi = ShadowSesi::query()
            ->where('kode_hash', hash('sha256', $kode))
            ->whereNull('diakhiri_pada')
            ->where('kode_kedaluwarsa_pada', '>', Carbon::now())
            ->with('reseller', 'admin')
            ->first();

        if (! $sesi) {
            throw ValidationException::withMessages([
                'kode' => ['Kode shadow salah atau sudah kedaluwarsa.'],
            ]);
        }

        if ($sesi->reseller->peran !== PeranAdminEnum::RESELLER || ! $sesi->reseller->status_aktif) {
            $sesi->forceDelete();
            throw ValidationException::withMessages([
                'kode' => ['Akun reseller tidak tersedia.'],
            ]);
        }

        // Token baru berumur pendek; nama shadow-{adminId} untuk audit middleware.
        $token = $sesi->reseller->createToken(
            'shadow-'.$sesi->admin_id,
            ['*'],
            Carbon::now()->addMinutes((int) env('SHADOW_TOKEN_EXPIRATION_MINUTES', 30)),
        );

        $sesi->update([
            'kode_hash' => null, // sekali pakai
            'kode_kedaluwarsa_pada' => null,
            'token_id' => $token->accessToken->id,
            'token_kedaluwarsa_pada' => $token->accessToken->expires_at,
        ]);

        return response()->json([
            'data' => [
                'token' => $token->plainTextToken,
                'reseller' => [
                    'id' => $sesi->reseller->id,
                    'nama_lengkap' => $sesi->reseller->nama_lengkap,
                    'peran' => $sesi->reseller->peran,
                    'foto_profil' => $sesi->reseller->foto_profil,
                ],
                'admin' => [
                    'id' => $sesi->admin->id,
                    'nama_lengkap' => $sesi->admin->nama_lengkap,
                ],
            ],
        ]);
    }

    /**
     * Akhiri shadow dari dalam portal reseller (tombol "Akhiri Shadow").
     */
    public function selesai(Request $request)
    {
        $token = $request->user()->currentAccessToken();
        $sesi = ShadowSesi::query()
            ->where('token_id', $token->id)
            ->whereNull('diakhiri_pada')
            ->first();

        if ($sesi) {
            $sesi->update([
                'diakhiri_pada' => Carbon::now(),
                'diakhiri_oleh' => $sesi->admin_id,
            ]);
        }

        $token->delete();

        return response()->json(['message' => 'Shadow diakhiri.']);
    }
}