<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Models\Party;
use App\Models\Currency;
use App\Models\ForexRemittance;
use App\Services\ForexFifoService;
use Carbon\Carbon;

class ForexRunTests extends Command
{
    protected $signature = 'forex:run-tests';
    protected $description = 'Run forex FIFO test sequences (sales/receipt/purchase/payment) with logs';

    public function handle()
    {
        $this->info("==== FOREX TEST RUN START ====");
        Log::info("==== FOREX TEST RUN START ====");

        // --------------------------------------------------------------------------------------
        // CLEAN DATA
        // --------------------------------------------------------------------------------------
        ForexRemittance::truncate();
        Log::info("Old remittance data cleared");

        // --------------------------------------------------------------------------------------
        // CREATE PARTY
        // --------------------------------------------------------------------------------------
        $party = Party::firstOrCreate(
            ['name' => 'Test Forex Party'],
            ['status' => 1]
        );

        Log::info("Party ID: {$party->id} created/loaded");

        // --------------------------------------------------------------------------------------
        // CREATE CURRENCIES
        // --------------------------------------------------------------------------------------
        $usd = Currency::firstOrCreate(['code' => 'USD'], ['exchange_rate' => 89]);
        $inr = Currency::firstOrCreate(['code' => 'INR'], ['exchange_rate' => 1]);

        Log::info("USD ID = {$usd->id}, INR ID = {$inr->id}");

        $fifo = app(ForexFifoService::class);

        // --------------------------------------------------------------------------------------
        // FIX 1: TIMESTAMPS ENSURE FIFO ORDERING CORRECT
        // --------------------------------------------------------------------------------------
        $t = Carbon::now()->subMinutes(20);

        // --------------------------------------------------------------------------------------
        // HELPER TO ADD FX TRANSACTION
        // --------------------------------------------------------------------------------------
        $add = function($type, $no, $amt, $rate) use ($party, $usd, $inr, &$t) {

            $direction = in_array($type, ['sale','payment']) ? 'debit' : 'credit';
            $ledger    = in_array($type, ['sale','receipt']) ? 'customer' : 'supplier';

            $t = $t->addSeconds(30); // maintain strict FIFO order

            $fx = ForexRemittance::create([
                'party_id'        => $party->id,
                'transaction_date'=> $t,
                'voucher_type'    => $type,
                'voucher_no'      => $no,
                'base_currency_id'=> $usd->id,
                'local_currency_id'=> $inr->id,
                'base_amount'     => $amt,
                'exchange_rate'   => $rate,
                'avg_rate'        => null,
                'closing_rate'    => null,
                'local_amount'    => round($amt * $rate, 4),
                'direction'       => $direction,
                'ledger_type'     => $ledger,
                'remaining_base_amount' => $amt,
                'settled_base_amount'   => 0,
                'realised_gain' => 0,
                'realised_loss' => 0,
                'unrealised_gain' => 0,
                'unrealised_loss' => 0,
            ]);

            Log::info("Created FX Txn ({$type}) => ", [
                'id' => $fx->id,
                'vch_no' => $no,
                'base' => $amt,
                'rate' => $rate,
                'ledger' => $ledger,
                'direction' => $direction,
                'timestamp' => $t
            ]);

            return $fx;
        };

        // --------------------------------------------------------------------------------------
        // TEST SEQUENCE
        // --------------------------------------------------------------------------------------
        $add('sale', 'S1', 1000, 89);      // Sale
        $add('receipt', 'R1', 1100, 89.2); // Receipt (advance)
        $add('sale', 'S2', 200, 89.1);     // Sale
        $add('receipt', 'R2', 150, 89);    // Receipt
        $add('purchase', 'P1', 1000, 90);  // Purchase
        $add('payment', 'PY1', 1100, 89.2); // Payment (advance)

        // --------------------------------------------------------------------------------------
        // RUN FIFO FOR CUSTOMER LEG
        // --------------------------------------------------------------------------------------
        Log::info("Running FIFO for CUSTOMER...");
        $fifo->applyFifoFor($party->id, 'customer', $usd->id);

        // --------------------------------------------------------------------------------------
        // RUN FIFO FOR SUPPLIER LEG
        // --------------------------------------------------------------------------------------
        Log::info("Running FIFO for SUPPLIER...");
        $fifo->applyFifoFor($party->id, 'supplier', $usd->id);

        // --------------------------------------------------------------------------------------
        // FINISHED
        // --------------------------------------------------------------------------------------
        $this->info("==== FOREX TEST RUN COMPLETE ====");
        Log::info("==== FOREX TEST RUN COMPLETE ====");
    }
}
