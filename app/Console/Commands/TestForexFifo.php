<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\ForexService;

class TestForexFifo extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'forex:fifo-test';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Simulate FIFO Forex Matching (Invoices ↔ Payments) and show realised/unrealised gain/loss step-by-step.';

    public function handle()
    {
        $this->info("\n💱 FIFO Forex Gain/Loss Simulation\n");

        // Test dataset (you can modify or make dynamic later)
        $transactions = [
            ['type' => 'purchase', 'base' => 100, 'rate' => 80, 'date' => '2025-11-01'],
            ['type' => 'payment', 'base' => 200, 'rate' => 81, 'date' => '2025-11-02'],
        ];

        $service = new ForexService();

        $ledger = [];
        $invoices = [];
        $payments = [];

        // Separate purchases and payments
        foreach ($transactions as $t) {
            if ($t['type'] === 'purchase') $invoices[] = $t;
            else $payments[] = $t;
        }

        $totalGainLoss = 0;

        foreach ($payments as $payment) {
            $remaining = $payment['base'];

            foreach ($invoices as &$invoice) {
                if ($invoice['base'] <= 0) continue;

                $applyBase = min($invoice['base'], $remaining);
                if ($applyBase <= 0) continue;

                $gainLoss = round(($payment['rate'] - $invoice['rate']) * $applyBase, 4);
                $invoiceLocal = round($applyBase * $invoice['rate'], 4);
                $paymentLocal = round($applyBase * $payment['rate'], 4);

                $ledger[] = [
                    'Type' => 'Invoice',
                    'Rate' => $invoice['rate'],
                    'Base' => $applyBase,
                    'Local' => $invoiceLocal,
                    'Gain/Loss' => '-',
                    'Status' => 'Settled',
                ];

                $ledger[] = [
                    'Type' => 'Payment',
                    'Rate' => $payment['rate'],
                    'Base' => $applyBase,
                    'Local' => $paymentLocal,
                    'Gain/Loss' => $gainLoss,
                    'Status' => 'Realised',
                ];

                $invoice['base'] -= $applyBase;
                $remaining -= $applyBase;
                $totalGainLoss += $gainLoss;

                if ($remaining <= 0.0001) break;
            }

            if ($remaining > 0.0001) {
                $unrealisedLocal = round($remaining * $payment['rate'], 4);
                $ledger[] = [
                    'Type' => 'Payment',
                    'Rate' => $payment['rate'],
                    'Base' => $remaining,
                    'Local' => $unrealisedLocal,
                    'Gain/Loss' => 0,
                    'Status' => 'Unrealised (Advance)',
                ];
            }
        }

        // Display ledger table
        $this->table(
            ['No', 'Type', 'Rate', 'Base', 'Local', 'Gain/Loss', 'Status'],
            collect($ledger)->map(function ($row, $i) {
                return [$i + 1, $row['Type'], $row['Rate'], $row['Base'], $row['Local'], $row['Gain/Loss'], $row['Status']];
            })->toArray()
        );

        $this->info("📊 Total Realised Gain/Loss: " . number_format($totalGainLoss, 2));
        $this->newLine();
        $this->info("💡 Logic: FIFO apply payments to invoices — remaining = unrealised advance.");
    }
}
