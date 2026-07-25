<?php

namespace App\Http\Controllers\Api\Operasional;

use App\Http\Controllers\Controller;
use App\Http\Requests\Operasional\SimpanPaketInternetRequest;
use App\Http\Requests\Operasional\UbahPaketInternetRequest;
use App\Models\PaketInternet;

class PaketInternetController extends Controller
{
    public function index()
    {
        $paket = PaketInternet::orderBy('kecepatan_mbps')->get();

        return response()->json(['data' => $paket]);
    }

    public function store(SimpanPaketInternetRequest $request)
    {
        $paket = PaketInternet::create($request->validated());

        return response()->json(['data' => $paket], 201);
    }

    public function show(PaketInternet $paketInternet)
    {
        return response()->json(['data' => $paketInternet]);
    }

    public function update(UbahPaketInternetRequest $request, PaketInternet $paketInternet)
    {
        $paketInternet->update($request->validated());

        return response()->json(['data' => $paketInternet->fresh()]);
    }

    public function destroy(PaketInternet $paketInternet)
    {
        $paketInternet->delete();

        return response()->json(['message' => 'Paket internet berhasil dihapus.']);
    }
}
