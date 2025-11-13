<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\ForexService;

class TestForexGainLoss extends Command
{
    /**
     * The name and signature of the console command.
     *
     * Usage:
     * php artisan forex:test 100 80 81
     *
     * @var string
     */
    protected $signature = 'forex:test 
                            {baseAmount : Base amount of transaction (e.g. 100)} 
                            {invoiceRate : Invoice rate (book rate, e.g. 80)} 
                            {paymentRate : Payment rate (actual rate, e.g. 81)} 
                            {closingRate? : Optional closing rate for unrealised (e.g. 82)}';

    protected $description = 'Test Forex Gain/Loss calculation (realised + unrealised)';

    public function handle()
    {
        $base = (float)$this->argument('baseAmount');
        $invoiceRate = (float)$this->argument('invoiceRate');
        $paymentRate = (float)$this->argument('paymentRate');
        $closingRate = (float)($this->argument('closingRate') ?? 0);

        $service = new ForexService();

        $this->info("\n💱 Forex Gain/Loss Test\n");

        // Step 1: Base conversions
        $invoiceLocal = $service::convertToLocal($base, $invoiceRate);
        $paymentLocal = $service::convertToLocal($base, $paymentRate);

        // Step 2: Realised gain/loss
        $realised = $service::realisedGainLoss($base, $paymentRate, $invoiceRate);

        // Step 3: Unrealised (if partial or closing provided)
        $unrealised = null;
        if ($closingRate > 0) {
            $unrealised = round(($closingRate - $paymentRate) * $base, 4);
        }

        // Step 4: Output
        $rows = [
            ['Invoice Rate', $invoiceRate],
            ['Payment Rate', $paymentRate],
            ['Closing Rate', $closingRate ?: '—'],
            ['Base Amount', $base],
            ['Invoice Local', $invoiceLocal],
            ['Payment Local', $paymentLocal],
            ['Realised Gain/Loss', $realised],
        ];

        if ($unrealised !== null) {
            $rows[] = ['Unrealised Gain/Loss', $unrealised];
        }

        $this->table(['Metric', 'Value'], $rows);

        // Interpretation summary
        if ($realised > 0) {
            $this->comment("✅ Realised Gain: {$realised}");
        } elseif ($realised < 0) {
            $this->error("❌ Realised Loss: {$realised}");
        } else {
            $this->info("No realised gain/loss.");
        }

        if ($unrealised !== null) {
            if ($unrealised > 0) {
                $this->comment("📈 Unrealised Gain: {$unrealised}");
            } elseif ($unrealised < 0) {
                $this->error("📉 Unrealised Loss: {$unrealised}");
            }
        }

        $this->newLine();
        $this->info("💡 Formula used: (PaymentRate - InvoiceRate) × Base");
        $this->info("   Optional Unrealised: (ClosingRate - PaymentRate) × Base");
    }
}
