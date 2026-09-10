<?php

namespace App\Http\Controllers\Api\Reseller;

use App\Enums\PeranAdminEnum;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Notifications\ResellerMintaUbahEmailNotification;
use App\Support\KompresiGambar;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class ResellerProfilController extends Controller
{
    public function show(Request $request)
    {
        return response()->json(['data' => $this->dataProfil($request->user())]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'nama_lengkap' => ['required', 'string', 'max:255'],
        ]);

        $request->user()->update($data);

        return response()->json(['data' => $this->dataProfil($request->user()->fresh())]);
    }

    /** Ajukan pergantian email — butuh persetujuan Admin Operasional/Super Admin. */
    public function mintaUbahEmail(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);
        $email = strtolower($data['email']);

        /** @var Admin $reseller */
        $reseller = $request->user();

        if ($email === $reseller->email) {
            throw ValidationException::withMessages([
                'email' => ['Email baru sama dengan email saat ini.'],
            ]);
        }

        $dipakai = Admin::query()
            ->where(fn ($q) => $q->where('email', $email)->orWhere('email_baru', $email))
            ->where('id', '!=', $reseller->id)
            ->exists();

        if ($dipakai) {
            throw ValidationException::withMessages([
                'email' => ['Email sudah digunakan akun lain.'],
            ]);
        }

        $reseller->update(['email_baru' => $email]);

        Notification::send(
            Admin::where('status_aktif', true)
                ->whereIn('peran', [PeranAdminEnum::OPERASIONAL, PeranAdminEnum::SUPER_ADMIN])
                ->get(),
            new ResellerMintaUbahEmailNotification($reseller, $email),
        );

        return response()->json(['data' => $this->dataProfil($reseller->fresh())]);
    }

    /** Batalkan permintaan ganti email yang masih menunggu persetujuan. */
    public function batalUbahEmail(Request $request)
    {
        /** @var Admin $reseller */
        $reseller = $request->user();

        if (! $reseller->email_baru) {
            throw ValidationException::withMessages([
                'email_baru' => ['Tidak ada permintaan ganti email yang tertunda.'],
            ]);
        }

        $reseller->update(['email_baru' => null]);

        return response()->json(['data' => $this->dataProfil($reseller->fresh())]);
    }

    public function ubahPassword(Request $request)
    {
        $data = $request->validate([
            'password_lama' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $reseller = $request->user();

        if (! Hash::check($data['password_lama'], $reseller->password)) {
            throw ValidationException::withMessages([
                'password_lama' => ['Password lama tidak sesuai.'],
            ]);
        }

        $reseller->update(['password' => $data['password']]);

        return response()->json(['data' => $this->dataProfil($reseller->fresh())]);
    }

    public function ubahFoto(Request $request)
    {
        $request->validate([
            'foto' => 'required|image|mimes:jpeg,png,jpg|max:2048',
        ]);

        $reseller = $request->user();

        $path = KompresiGambar::simpanKeWebp($request->file('foto'), 'profil-reseller');
        $reseller->update(['foto_profil' => $path]);

        return response()->json(['data' => $this->dataProfil($reseller->fresh())]);
    }

    private function dataProfil(Admin $reseller): array
    {
        return [
            'id' => $reseller->id,
            'nama_lengkap' => $reseller->nama_lengkap,
            'email' => $reseller->email,
            'email_baru' => $reseller->email_baru,
            'foto_profil' => $reseller->foto_profil,
            'peran' => $reseller->peran,
        ];
    }
}