<?php

namespace App\Http\Controllers\Api\Reseller;

use App\Http\Controllers\Controller;
use App\Mail\ResellerOtpEmail;
use App\Models\Admin;
use App\Support\KompresiGambar;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
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

        $otpCode = (string) random_int(100000, 999999);
        $expiresAt = now()->addMinutes(10);

        $reseller->update([
            'email_baru' => $email,
            'otp_code' => $otpCode,
            'otp_expires_at' => $expiresAt,
        ]);

        Mail::to($email)->send(new ResellerOtpEmail($otpCode, $reseller->nama_lengkap));

        return response()->json([
            'message' => 'Kode verifikasi dikirim ke email baru. Silakan cek inbox Anda.',
            'data' => $this->dataProfil($reseller->fresh()),
        ]);
    }

    public function verifikasiOtpEmail(Request $request)
    {
        $data = $request->validate([
            'otp' => ['required', 'string', 'size:6'],
        ]);

        /** @var Admin $reseller */
        $reseller = $request->user();

        if (! $reseller->email_baru || ! $reseller->otp_code || ! $reseller->otp_expires_at) {
            throw ValidationException::withMessages([
                'otp' => ['Tidak ada permintaan ganti email yang tertunda.'],
            ]);
        }

        if (now()->gt($reseller->otp_expires_at)) {
            $reseller->update([
                'email_baru' => null,
                'otp_code' => null,
                'otp_expires_at' => null,
            ]);
            throw ValidationException::withMessages([
                'otp' => ['Kode verifikasi sudah kedaluwarsa. Silakan ajukan ulang.'],
            ]);
        }

        if ($data['otp'] !== $reseller->otp_code) {
            throw ValidationException::withMessages([
                'otp' => ['Kode verifikasi tidak sesuai.'],
            ]);
        }

        $reseller->update([
            'email' => $reseller->email_baru,
            'email_baru' => null,
            'otp_code' => null,
            'otp_expires_at' => null,
        ]);

        return response()->json([
            'message' => 'Email berhasil diperbarui.',
            'data' => $this->dataProfil($reseller->fresh()),
        ]);
    }

    public function batalUbahEmail(Request $request)
    {
        /** @var Admin $reseller */
        $reseller = $request->user();

        if (! $reseller->email_baru) {
            throw ValidationException::withMessages([
                'email_baru' => ['Tidak ada permintaan ganti email yang tertunda.'],
            ]);
        }

        $reseller->update([
            'email_baru' => null,
            'otp_code' => null,
            'otp_expires_at' => null,
        ]);

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