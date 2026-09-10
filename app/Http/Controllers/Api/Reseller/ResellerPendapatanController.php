<?php

namespace App\Http\Controllers\Api\Reseller;

use App\Http\Controllers\Api\Keuangan\PendapatanController;
use Illuminate\Http\Request;

class ResellerPendapatanController extends PendapatanController
{
    /** Semua endpoint pendapatan reseller di-scope ke pelanggan milik reseller yang login. */
    private function scopeKeReseller(Request $request): void
    {
        $this->resellerId = $request->user()->id;
    }

    public function index(Request $request)
    {
        $this->scopeKeReseller($request);

        return parent::index($request);
    }

    public function pelangganList()
    {
        $this->scopeKeReseller(request());

        return parent::pelangganList();
    }

    public function report(Request $request)
    {
        $this->scopeKeReseller($request);

        return parent::report($request);
    }

    public function reportExcel(Request $request)
    {
        $this->scopeKeReseller($request);

        return parent::reportExcel($request);
    }
}