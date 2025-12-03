<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Request;
use App\Http\Controllers\ForexRemittanceController;

class ForexRunTests extends Command
{
    protected $signature = 'forex:run-tests';
    protected $description = 'Run V5 Forex manual test cases through store() controller route.';

    public function handle()
    {
        $this->info("=== Running V5 Forex Test Suite (store() based) ===");

        $controller = new ForexRemittanceController();

        // Utility function
        $callStore = function(array $data) use ($controller) {
            $req = Request::create('/fake-url', 'POST', $data);
            $controller->store($req);
            $this->info("→ store() executed for {$data['voucher_type']} {$data['voucher_no']}");
        };

        // =============================
        // START: FULL TEST SCENARIOS
        // =============================

        $this->section("1. SALE - Unmatched Invoice (Unrealised Case)");
        $callStore([
            '_token' => csrf_token(),
            'party_type' => 'customer',
            'party_id' => 1,
            'transaction_date' => '2025-12-01',
            'base_currency_id' => 1,
            'local_currency_id' => 2,
            'base_amount' => 500,
            'exchange_rate' => 80,
            'avg_rate' => 82,
            'closing_rate' => 82,
            'voucher_type' => 'sale',
            'voucher_no' => 'S1',
        ]);

        $this->section("2. RECEIPT - Partial Settlement (200 @85)");
        $callStore([
            '_token' => csrf_token(),
            'party_type' => 'customer',
            'party_id' => 1,
            'transaction_date' => '2025-11-01',
            'base_currency_id' => 1,
            'local_currency_id' => 2,
            'base_amount' => 200,
            'exchange_rate' => 85,
            'avg_rate' => 85,
            'closing_rate' => 85,
            'voucher_type' => 'receipt',
            'voucher_no' => 'R1',
        ]);

        $this->section("3. PURCHASE - Unmatched (Unrealised Gain)");
        $callStore([
            '_token' => csrf_token(),
            'party_type' => 'customer',
            'party_id' => 1,
            'transaction_date' => '2025-12-01',
            'base_currency_id' => 1,
            'local_currency_id' => 2,
            'base_amount' => 500,
            'exchange_rate' => 80,
            'avg_rate' => 81,
            'closing_rate' => 81,
            'voucher_type' => 'purchase',
            'voucher_no' => 'P1',
        ]);

        $this->section("4. PAYMENT - Overpayment (1000 @78)");
        $callStore([
            '_token' => csrf_token(),
            'party_type' => 'customer',
            'party_id' => 1,
            'transaction_date' => '2025-12-01',
            'base_currency_id' => 1,
            'local_currency_id' => 2,
            'base_amount' => 1000,
            'exchange_rate' => 78,
            'voucher_type' => 'payment',
            'voucher_no' => 'PAY1',
        ]);

        // =============================
        // MORE ADVANCED MIX CASES
        // =============================

        $this->section("5. SALE fully matched w/ multi receipts");
        $callStore([
            '_token'=> csrf_token(),
            'party_type'=>'customer',
            'party_id'=>1,
            'transaction_date'=>'2025-12-10',
            'base_currency_id'=>1,
            'local_currency_id'=>2,
            'base_amount'=>300,
            'exchange_rate'=>75,
            'voucher_type'=>'sale',
            'voucher_no'=>'S2',
        ]);

        $callStore([
            '_token'=> csrf_token(),
            'party_type'=>'customer',
            'party_id'=>1,
            'transaction_date'=>'2025-12-11',
            'base_currency_id'=>1,
            'local_currency_id'=>2,
            'base_amount'=>100,
            'exchange_rate'=>70,
            'voucher_type'=>'receipt',
            'voucher_no'=>'R2a',
        ]);

        $callStore([
            '_token'=> csrf_token(),
            'party_type'=>'customer',
            'party_id'=>1,
            'transaction_date'=>'2025-12-12',
            'base_currency_id'=>1,
            'local_currency_id'=>2,
            'base_amount'=>200,
            'exchange_rate'=>80,
            'voucher_type'=>'receipt',
            'voucher_no'=>'R2b',
        ]);

        $this->section("6. PURCHASE fully matched w/ multi payments");
        $callStore([
            '_token'=> csrf_token(),
            'party_type'=>'customer',
            'party_id'=>1,
            'transaction_date'=>'2025-12-20',
            'base_currency_id'=>1,
            'local_currency_id'=>2,
            'base_amount'=>300,
            'exchange_rate'=>75,
            'voucher_type'=>'purchase',
            'voucher_no'=>'P2',
        ]);

        $callStore([
            '_token'=> csrf_token(),
            'party_type'=>'customer',
            'party_id'=>1,
            'transaction_date'=>'2025-12-21',
            'base_currency_id'=>1,
            'local_currency_id'=>2,
            'base_amount'=>100,
            'exchange_rate'=>78,
            'voucher_type'=>'payment',
            'voucher_no'=>'PAY2a',
        ]);

        $callStore([
            '_token'=> csrf_token(),
            'party_type'=>'customer',
            'party_id'=>1,
            'transaction_date'=>'2025-12-22',
            'base_currency_id'=>1,
            'local_currency_id'=>2,
            'base_amount'=>200,
            'exchange_rate'=>72,
            'voucher_type'=>'payment',
            'voucher_no'=>'PAY2b',
        ]);

        // =============================
        // BACKDATED EXTREME CASES
        // =============================

        $this->section("7. Receipt BEFORE Sale (advance then FIFO realignment)");
        $callStore([
            '_token'=> csrf_token(),
            'party_type'=>'customer',
            'party_id'=>1,
            'transaction_date'=>'2025-10-01',
            'base_currency_id'=>1,
            'local_currency_id'=>2,
            'base_amount'=>400,
            'exchange_rate'=>82,
            'voucher_type'=>'receipt',
            'voucher_no'=>'R_ADV1',
        ]);

        $callStore([
            '_token'=> csrf_token(),
            'party_type'=>'customer',
            'party_id'=>1,
            'transaction_date'=>'2025-11-15',
            'base_currency_id'=>1,
            'local_currency_id'=>2,
            'base_amount'=>400,
            'exchange_rate'=>79,
            'voucher_type'=>'sale',
            'voucher_no'=>'S_LATE1',
        ]);

        $this->section("8. Payment BEFORE Purchase (advance then realignment)");
        $callStore([
            '_token'=> csrf_token(),
            'party_type'=>'customer',
            'party_id'=>1,
            'transaction_date'=>'2025-09-01',
            'base_currency_id'=>1,
            'local_currency_id'=>2,
            'base_amount'=>300,
            'exchange_rate'=>76,
            'voucher_type'=>'payment',
            'voucher_no'=>'PAY_ADV1',
        ]);

        $callStore([
            '_token'=> csrf_token(),
            'party_type'=>'customer',
            'party_id'=>1,
            'transaction_date'=>'2025-10-10',
            'base_currency_id'=>1,
            'local_currency_id'=>2,
            'base_amount'=>300,
            'exchange_rate'=>85,
            'voucher_type'=>'purchase',
            'voucher_no'=>'P_LATE1',
        ]);

        // =============================
        // END
        // =============================
        $this->info("\n=== V5 Test Suite Completed ===");
        return Command::SUCCESS;
    }

    private function section($txt)
    {
        $this->info("\n----------------------------------------------");
        $this->info(">> $txt");
        $this->info("----------------------------------------------");
    }
}
