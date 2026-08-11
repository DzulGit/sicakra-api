<?php

namespace App\Http\Controllers\Api\Pelanggan;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pelanggan\UbahPasswordRequest;
use App\Http\Requests\Pelanggan\UbahProfilRequest;
use App\Http\Requests\Pelanggan\UbahUsernameRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class ProfilController extends Controller
{
    public function show(Request $request)
    {
        return response()->json(['data' => $request->user()]);
    }

    public function update(UbahProfilRequest $request)
    {
        $pelanggan = $request->user();
        $pelanggan->update($request->validated());

        return response()->json(['data' => $pelanggan->fresh()]);
    }

    public function ubahUsername(UbahUsernameRequest $request)
    {
        $pelanggan = $request->user();
        $pelanggan->update(['username' => $request->validated('username')]);

        return response()->json(['data' => $pelanggan->fresh()]);
    }

    public function ubahPassword(UbahPasswordRequest $request)
    {
        $pelanggan = $request->user();

        // Kalau pelanggan sudah pernah membuat password sendiri, wajib
        // verifikasi password lama dulu sebelum diganti (dobel-cek di luar
        // aturan 'required' di Form Request, karena 'required' cuma cek
        // field-nya ada, bukan cek kecocokannya).
        if ($pelanggan->password_sudah_dibuat && ! Hash::check($request->validated('password_lama'), $pelanggan->password)) {
            throw ValidationException::withMessages([
                'password_lama' => ['Password lama tidak sesuai.'],
            ]);
        }

        $pelanggan->update([
            'password' => $request->validated('password'), // otomatis di-hash via cast 'hashed'
            'password_sudah_dibuat' => true,
        ]);

        return response()->json(['data' => ['message' => 'Password berhasil diperbarui.']]);
    }

    public function ubahFoto(Request $request)
    {
        $request->validate([
            'foto' => 'required|image|mimes:jpeg,png,jpg|max:2048',
        ]);

        $pelanggan = $request->user();
        
        $path = $request->file('foto')->store('profil', 's3');
        $pelanggan->update(['foto_profil' => $path]);

        return response()->json(['data' => $pelanggan->fresh()]);
    }
}