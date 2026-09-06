<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\BuatPasswordPelangganRequest;
use App\Http\Requests\Auth\LoginPelangganRequest;
use App\Http\Requests\Auth\LoginPertamaPelangganRequest;
use App\Models\Pelanggan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthPelangganController extends Controller
{
    /**
     * Login pelanggan. Menerima baik pelanggan yang sudah pernah membuat
     * password sendiri, maupun yang masih pakai password default
     * (= nomor_pelanggan, di-set otomatis saat AktivasiAkunPelangganService
     * jalan). Validasi cukup lewat Hash::check() — kolom password_sudah_dibuat
     * TIDAK dipakai sebagai filter di sini, hanya untuk menentukan apakah
     * banner "ganti username & password" perlu ditampilkan di dashboard.
     */
    public function login(LoginPelangganRequest $request)
    {
        $data = $request->validated();

        $pelanggan = Pelanggan::where('username', $data['username'])->first();

        if (! $pelanggan || ! Hash::check($data['password'], $pelanggan->password)) {
            throw ValidationException::withMessages([
                'username' => ['Username atau password salah.'],
            ]);
        }

        // Menimpa token lama milik akun ini supaya tidak ada sesi ganda
        // yang tersisa saat akun di-login ulang (switch account / relogin).
        $pelanggan->tokens()->delete();

        $token = $pelanggan->createToken('pelanggan-token')->plainTextToken;

        return response()->json([
            'data' => [
                'pelanggan' => $pelanggan,
                'token' => $token,
            ],
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Berhasil logout.',
        ]);
    }

    /** Login pertama pelanggan baru (belum pernah buat password). */
    public function loginPertama(LoginPertamaPelangganRequest $request)
    {
        $data = $request->validated();

        $pelanggan = Pelanggan::where('nomor_pelanggan', $data['nomor_pelanggan'])
            ->where('nomor_hp', $data['nomor_hp'])
            ->first();

        if (! $pelanggan) {
            throw ValidationException::withMessages([
                'nomor_pelanggan' => ['Nomor pelanggan atau nomor HP tidak ditemukan.'],
            ]);
        }

        if ($pelanggan->password_sudah_dibuat) {
            throw ValidationException::withMessages([
                'nomor_pelanggan' => ['Anda sudah pernah membuat password. Silakan login dengan username & password Anda.'],
            ]);
        }

        $token = $pelanggan->createToken('pelanggan-token')->plainTextToken;

        return response()->json([
            'data' => [
                'pelanggan' => $pelanggan,
                'token' => $token,
                'wajib_buat_password' => true,
            ],
        ]);
    }

    /** Buat password pertama kali — harus login dulu (auth:sanctum) via login-pertama. */
    public function buatPassword(BuatPasswordPelangganRequest $request)
    {
        $pelanggan = $request->user();

        $pelanggan->update([
            'password' => $request->validated()['password'],
            'password_sudah_dibuat' => true,
        ]);

        $token = $pelanggan->createToken('pelanggan-token')->plainTextToken;

        return response()->json([
            'data' => [
                'pelanggan' => $pelanggan->fresh(),
                'token' => $token,
                'wajib_buat_password' => false,
            ],
        ]);
    }
}
