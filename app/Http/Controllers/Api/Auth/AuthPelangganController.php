<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginPelangganRequest;
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
}