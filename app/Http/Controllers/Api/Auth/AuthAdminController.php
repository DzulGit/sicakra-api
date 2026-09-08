<?php

namespace App\Http\Controllers\Api\Auth;

use App\Enums\PeranAdminEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginAdminRequest;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthAdminController extends Controller
{
    /** Login portal ADMIN INTERNAL — reseller tidak boleh masuk lewat sini. */
    public function login(LoginAdminRequest $request)
    {
        $data = $request->validated();

        $admin = Admin::where('email', $data['email'])->first();

        // Pesan error sengaja digeneralisasi (tidak membedakan "email tidak ada"
        // vs "password salah" vs "akun nonaktif" vs "bukan admin internal")
        // untuk mencegah user enumeration.
        if (
            ! $admin
            || ! $admin->status_aktif
            || $admin->peran === PeranAdminEnum::RESELLER
            || ! Hash::check($data['password'], $admin->password)
        ) {
            throw ValidationException::withMessages([
                'email' => ['Email atau password salah.'],
            ]);
        }

        return $this->buatToken($admin);
    }

    /** Login PORTAL RESELLER — hanya akun berperan reseller. */
    public function loginReseller(LoginAdminRequest $request)
    {
        $admin = Admin::where('email', $request->validated()['email'])->first();

        if (
            ! $admin
            || ! $admin->status_aktif
            || $admin->peran !== PeranAdminEnum::RESELLER
            || ! Hash::check($request->validated()['password'], $admin->password)
        ) {
            throw ValidationException::withMessages([
                'email' => ['Email atau password salah.'],
            ]);
        }

        return $this->buatToken($admin);
    }

    private function buatToken(Admin $admin)
    {
        // Menimpa token lama milik akun ini supaya tidak ada sesi ganda
        // yang tersisa saat akun di-login ulang (switch account / relogin).
        $admin->tokens()->delete();

        $token = $admin->createToken('admin-token')->plainTextToken;

        return response()->json([
            'data' => [
                'admin' => $admin,
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
