<?php

namespace App\Http\Controllers\Report;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ForexReportController extends Controller
{
    public function partyLedger(Request $request)
    {
        return view('backend.report.forex.party_ledger');
    }

    public function invoiceLedger(Request $request)
    {
        return view('backend.report.forex.invoice_ledger');
    }

    public function currencyLedger(Request $request)
    {
        return view('backend.report.forex.currency_ledger');
    }

    public function realised(Request $request)
    {
        return view('backend.report.forex.realised');
    }

    public function unrealised(Request $request)
    {
        return view('backend.report.forex.unrealised');
    }

    public function summary(Request $request)
    {
        return view('backend.report.forex.summary');
    }
}
