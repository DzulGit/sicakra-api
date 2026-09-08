<?php

namespace App\Http\Controllers\Api\Reseller;

use App\Http\Controllers\Controller;
use App\Models\PaketInternet;
use Illuminate\Http\Request;

class PaketInternetController extends Controller
{
    public function index(Request $request)
    {
        $paket = PaketInternet::where('reseller_id', $request->user()->id)->get();
        return response()->json(['data' => $paket]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nama_paket' => 'required|string|max:255',
            'kecepatan_mbps' => 'required|integer|min:1',
            'harga' => 'required|numeric|min:0',
            'jumlah_perangkat' => 'nullable|integer|min:1',
            'deskripsi' => 'nullable|string',
        ]);

        $validated['reseller_id'] = $request->user()->id;
        $validated['status_aktif'] = true;

        $paket = PaketInternet::create($validated);

        return response()->json(['message' => 'Paket berhasil dibuat.', 'data' => $paket], 201);
    }

    public function show(Request $request, PaketInternet $paketInternet)
    {
        if ($paketInternet->reseller_id !== $request->user()->id) abort(404);
        return response()->json(['data' => $paketInternet]);
    }

    public function update(Request $request, PaketInternet $paketInternet)
    {
        if ($paketInternet->reseller_id !== $request->user()->id) abort(404);

        $validated = $request->validate([
            'nama_paket' => 'sometimes|required|string|max:255',
            'kecepatan_mbps' => 'sometimes|required|integer|min:1',
            'harga' => 'sometimes|required|numeric|min:0',
            'jumlah_perangkat' => 'nullable|integer|min:1',
            'deskripsi' => 'nullable|string',
            'status_aktif' => 'sometimes|boolean',
        ]);

        $paketInternet->update($validated);

        return response()->json(['message' => 'Paket berhasil diperbarui.', 'data' => $paketInternet]);
    }

    public function destroy(Request $request, PaketInternet $paketInternet)
    {
        if ($paketInternet->reseller_id !== $request->user()->id) abort(404);

        $digunakan = $paketInternet->layananInternet()->exists();
        if ($digunakan) {
            return response()->json(['message' => 'Paket tidak bisa dihapus karena sedang digunakan pelanggan.'], 422);
        }

        $paketInternet->delete();
        return response()->json(['message' => 'Paket berhasil dihapus.']);
    }
}