<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Carbon\Carbon;

use App\Http\Controllers\ForexRemittanceController;
use App\Models\ForexRemittance;
use App\Models\ForexMatch;

class ForexRunTests extends Command
{
    protected $signature = 'forex:run-tests';
    protected $description = 'Run full FIFO Forex test scenarios using store() + matching';

    public function handle()
    {
        $this->info("=======================================================");
        $this->info("🔥 FOREX FULL TEST SUITE STARTED");
        $this->info("=======================================================\n");

        Log::info("🔥 forex:run-tests started");

        // -----------------------------------------------------
        // CLEAN DATABASE SAFELY
        // -----------------------------------------------------
        $this->cleanDatabase();

        // -----------------------------------------------------
        // RUN TEST CASES
        // -----------------------------------------------------
        $controller = new ForexRemittanceController;

        $this->info("\n➡️ Running Test Case 1: Receipt R1");
        $this->callStore($controller, [
            'party_id' => 1,
            'party_type' => 'customer',
            'transaction_date' => '2025-11-28',
            'base_currency_id' => 1,
            'local_currency_id' => 2,
            'base_amount' => 1000,
            'exchange_rate' => 80,
            'local_amount' => 80000,
            'avg_rate' => 80,
            'closing_rate' => 80,
            'voucher_type' => 'receipt',
            'voucher_no' => 'R1'
        ]);

        $this->info("\n➡️ Running Test Case 2: Sale S1");
        $this->callStore($controller, [
            'party_id' => 1,
            'party_type' => 'customer',
            'transaction_date' => '2025-11-28',
            'base_currency_id' => 1,
            'local_currency_id' => 2,
            'base_amount' => 500,
            'exchange_rate' => 82,
            'local_amount' => 41000,
            'avg_rate' => 82,
            'closing_rate' => 82,
            'voucher_type' => 'sale',
            'voucher_no' => 'S1'
        ]);

        $this->info("\n➡️ Running Test Case 3: Sale S2");
        $this->callStore($controller, [
            'party_id' => 1,
            'party_type' => 'supplier',
            'transaction_date' => '2025-11-28',
            'base_currency_id' => 1,
            'local_currency_id' => 2,
            'base_amount' => 1000,
            'exchange_rate' => 76,
            'local_amount' => 76000,
            'avg_rate' => 76,
            'closing_rate' => 76,
            'voucher_type' => 'sale',
            'voucher_no' => 'S2'
        ]);

        $this->info("\n➡️ Running Test Case 4: Receipt R2");
        $this->callStore($controller, [
            'party_id' => 1,
            'party_type' => 'customer',
            'transaction_date' => '2025-11-28',
            'base_currency_id' => 1,
            'local_currency_id' => 2,
            'base_amount' => 500,
            'exchange_rate' => 83,
            'local_amount' => 41500,
            'avg_rate' => 83,
            'closing_rate' => 83,
            'voucher_type' => 'receipt',
            'voucher_no' => 'R2'
        ]);

        $this->info("\n➡️ Running Test Case 5: Purchase P1");
        $this->callStore($controller, [
            'party_id' => 1,
            'party_type' => 'supplier',
            'transaction_date' => '2025-11-28',
            'base_currency_id' => 1,
            'local_currency_id' => 2,
            'base_amount' => 1000,
            'exchange_rate' => 75,
            'local_amount' => 75000,
            'avg_rate' => 75,
            'closing_rate' => 77,
            'voucher_type' => 'purchase',
            'voucher_no' => 'P1'
        ]);

        $this->info("\n➡️ Running Test Case 6: Payment PAY1");
        $this->callStore($controller, [
            'party_id' => 1,
            'party_type' => 'supplier',
            'transaction_date' => '2025-11-28',
            'base_currency_id' => 1,
            'local_currency_id' => 2,
            'base_amount' => 600,
            'exchange_rate' => 74,
            'local_amount' => 44400,
            'avg_rate' => 74,
            'closing_rate' => 74,
            'voucher_type' => 'payment',
            'voucher_no' => 'PAY1'
        ]);

        // -----------------------------------------------------
        // SHOW FINAL LEDGER SNAPSHOT
        // -----------------------------------------------------
        $this->showLedger();

        $this->info("\n=======================================================");
        $this->info("🔥 FOREX FULL TEST SUITE COMPLETED");
        $this->info("=======================================================\n");

        return 0;
    }

    // ----------------------------------------------------------------------
    // CLEAN DB
    // ----------------------------------------------------------------------
    private function cleanDatabase()
    {
        $this->info("🧹 Cleaning database...");
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');

        DB::table('forex_matches')->delete();
        DB::table('forex_remittances')->delete();

        DB::statement('ALTER TABLE forex_matches AUTO_INCREMENT = 1;');
        DB::statement('ALTER TABLE forex_remittances AUTO_INCREMENT = 1;');

        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $this->info("✔ Database cleaned.\n");
    }

    // ----------------------------------------------------------------------
    // CALL STORE() SAFELY
    // ----------------------------------------------------------------------
    private function callStore($controller, array $data)
    {
        $request = new Request($data);

        Log::info("➡️ Test Input", $data);
        $controller->store($request);

        $this->info("✔ Stored: " . $data['voucher_type'] . " " . $data['voucher_no']);
    }

    // ----------------------------------------------------------------------
    // DISPLAY LEDGER
    // ----------------------------------------------------------------------
    private function showLedger()
    {
        $this->info("\n📘 FINAL LEDGER (forex_remittances)");

        $rows = ForexRemittance::orderBy('id')->get();

        foreach ($rows as $r) {
            $this->line(
                "ID {$r->id} | {$r->voucher_type} {$r->voucher_no} | Base {$r->base_amount} | Settled {$r->settled_base_amount} | Rem {$r->remaining_base_amount}"
            );
        }

        $this->info("\n📙 MATCH RECORDS:");
        foreach (ForexMatch::orderBy('id')->get() as $m) {
            $this->line(
                "Match #{$m->id} | Invoice {$m->invoice_id} | Settlement {$m->settlement_id} | Base {$m->matched_base_amount} | Realised {$m->realised_gain_loss}"
            );
        }
    }
}
