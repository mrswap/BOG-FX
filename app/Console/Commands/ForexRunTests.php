<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Request;
use App\Http\Controllers\ForexRemittanceController;

class ForexRunTests extends Command
{
    protected $signature = 'forex:test {case?}';
    protected $description = 'Run predefined Forex Remittance test cases';

    public function handle()
    {
        $this->info("=== BOG Trading — Forex Test Runner ===");

        $cases = [
            '1'  => 'SALE → RECEIPT (full settlement)',
            '2'  => 'SALE → PARTIAL RECEIPT',
            '3'  => 'RECEIPT → ADVANCE (before sale)',
            '4'  => 'PURCHASE → PAYMENT (full settlement)',
            '5'  => 'PURCHASE → PARTIAL PAYMENT',
            '6'  => 'PAYMENT → ADVANCE (before purchase)',
            '7'  => 'MULTI SALE + MULTI RECEIPT (FIFO)',
            '8'  => 'MULTI PURCHASE + MULTI PAYMENT (FIFO)',
            '9'  => 'OVER-RECEIPT (ADVANCE)',
            '10' => 'OVER-PAYMENT (ADVANCE)',
            '11' => 'BACKDATED RECEIPT CASE',
            '12' => 'BACKDATED PAYMENT CASE',
        ];

        // Display table of cases
        $this->table(['Case', 'Description'], collect($cases)->map(fn($v,$k) => [
            'case' => $k,
            'desc' => $v
        ]));

        $case = $this->argument('case') ?: $this->ask("Select test case number:");

        // RUN ALL CASES
        if ($case == '9999') {
            $this->info("\nRunning ALL TEST CASES...\n");

            foreach ($cases as $num => $desc) {
                $this->runSingleCase($num, $desc);
            }
            return 0;
        }

        // RUN SINGLE CASE
        if (!isset($cases[$case])) {
            $this->error("Invalid test case.");
            return 0;
        }

        $this->runSingleCase($case, $cases[$case]);
        return 0;
    }

    /**
     * RUN SINGLE TEST CASE
     */
    private function runSingleCase($case, $desc)
    {
        $this->line("\n==========================");
        $this->info("Running Test Case {$case}: {$desc}");
        $this->line("==========================\n");

        $payloads = $this->buildTestPayload($case);

        if (!$payloads) {
            $this->error("No payload defined for this case.");
            return;
        }

        if (!is_array($payloads[0])) {
            // Single transaction case — convert to list
            $payloads = [ $payloads ];
        }

        $controller = app()->make(ForexRemittanceController::class);

        foreach ($payloads as $p) {

            $this->info("\n▶ Txn Payload:");
            $this->line(json_encode($p, JSON_PRETTY_PRINT));

            $req = new Request($p);
            $response = $controller->store($req);

            $this->info("✔ Stored.");
            $this->line("------------------------------");
        }
    }

    /**
     * Helper for unique Voucher Nos
     */
    private function vch($prefix)
    {
        return $prefix . '-' . rand(10000, 99999);
    }

    /**
     * Predefined test datasets for 12 test cases
     */
    private function buildTestPayload($case)
    {
        switch ($case) {

            // 1) SALE → RECEIPT (FULL)
            case '1':
                return [
                    [
                        'party_type' => 'customer',
                        'party_id' => 1,
                        'transaction_date' => now()->toDateString(),
                        'base_currency_id' => 1,
                        'base_amount' => 100,
                        'closing_rate' => 82.5,
                        'local_currency_id' => 2,
                        'exchange_rate' => 80,
                        'local_amount' => 8000,
                        'voucher_type' => 'sale',
                        'voucher_no' => $this->vch('S-TEST-1'),
                        'remarks' => 'Test Sale Full',
                    ],
                    [
                        'party_type' => 'customer',
                        'party_id' => 1,
                        'transaction_date' => now()->toDateString(),
                        'base_currency_id' => 1,
                        'base_amount' => 100,
                        'closing_rate' => 82.5,
                        'local_currency_id' => 2,
                        'exchange_rate' => 85,
                        'local_amount' => 8500,
                        'voucher_type' => 'receipt',
                        'voucher_no' => $this->vch('R-TEST-1'),
                        'remarks' => 'Receipt full settlement',
                    ]
                ];

            // 2) PARTIAL RECEIPT
            case '2':
                return [
                    [
                        'party_type' => 'customer',
                        'party_id' => 1,
                        'transaction_date' => now()->toDateString(),
                        'base_currency_id' => 1,
                        'base_amount' => 100,
                        'exchange_rate' => 80,
                        'local_currency_id' => 2,
                        'local_amount' => 8000,
                        'voucher_type' => 'sale',
                        'voucher_no' => $this->vch('S2'),
                    ],
                    [
                        'party_type' => 'customer',
                        'party_id' => 1,
                        'transaction_date' => now()->toDateString(),
                        'base_currency_id' => 1,
                        'base_amount' => 50,
                        'exchange_rate' => 85,
                        'local_currency_id' => 2,
                        'local_amount' => 4250,
                        'voucher_type' => 'receipt',
                        'voucher_no' => $this->vch('R2'),
                    ]
                ];

            // 3) ADVANCE RECEIPT
            case '3':
                return [
                    [
                        'party_type' => 'customer',
                        'party_id' => 1,
                        'transaction_date' => now()->subDays(1)->toDateString(),
                        'base_currency_id' => 1,
                        'base_amount' => 100,
                        'exchange_rate' => 83,
                        'local_currency_id' => 2,
                        'local_amount' => 8300,
                        'voucher_type' => 'receipt',
                        'voucher_no' => $this->vch('ADV-R'),
                    ]
                ];

            // 4) PURCHASE → PAYMENT (FULL)
            case '4':
                return [
                    [
                        'party_type' => 'supplier',
                        'party_id' => 1,
                        'transaction_date' => now()->toDateString(),
                        'base_currency_id' => 1,
                        'base_amount' => 200,
                        'exchange_rate' => 82,
                        'local_currency_id' => 2,
                        'local_amount' => 16400,
                        'voucher_type' => 'purchase',
                        'voucher_no' => $this->vch('P4'),
                    ],
                    [
                        'party_type' => 'supplier',
                        'party_id' => 1,
                        'transaction_date' => now()->toDateString(),
                        'base_currency_id' => 1,
                        'base_amount' => 200,
                        'exchange_rate' => 78,
                        'local_currency_id' => 2,
                        'local_amount' => 15600,
                        'voucher_type' => 'payment',
                        'voucher_no' => $this->vch('PAY4'),
                    ]
                ];

            // 5) PARTIAL PAYMENT
            case '5':
                return [
                    [
                        'party_type' => 'supplier',
                        'party_id' => 1,
                        'transaction_date' => now()->toDateString(),
                        'base_currency_id' => 1,
                        'base_amount' => 200,
                        'exchange_rate' => 82,
                        'local_currency_id' => 2,
                        'local_amount' => 16400,
                        'voucher_type' => 'purchase',
                        'voucher_no' => $this->vch('P5'),
                    ],
                    [
                        'party_type' => 'supplier',
                        'party_id' => 1,
                        'transaction_date' => now()->toDateString(),
                        'base_currency_id' => 1,
                        'base_amount' => 100,
                        'exchange_rate' => 78,
                        'local_currency_id' => 2,
                        'local_amount' => 7800,
                        'voucher_type' => 'payment',
                        'voucher_no' => $this->vch('PAY5'),
                    ]
                ];

            // 6) ADVANCE PAYMENT
            case '6':
                return [
                    [
                        'party_type' => 'supplier',
                        'party_id' => 1,
                        'transaction_date' => now()->subDays(1)->toDateString(),
                        'base_currency_id' => 1,
                        'base_amount' => 150,
                        'exchange_rate' => 79,
                        'local_currency_id' => 2,
                        'local_amount' => 11850,
                        'voucher_type' => 'payment',
                        'voucher_no' => $this->vch('ADV-P'),
                    ]
                ];

            // 7) MULTI SALE + MULTI RECEIPT (FIFO)
            case '7':
                return [
                    [
                        'party_type' => 'customer',
                        'party_id' => 1,
                        'transaction_date' => '2025-12-01',
                        'base_currency_id' => 1,
                        'base_amount' => 100,
                        'exchange_rate' => 80,
                        'local_currency_id' => 2,
                        'local_amount' => 8000,
                        'voucher_type' => 'sale',
                        'voucher_no' => $this->vch('S7-A'),
                    ],
                    [
                        'party_type' => 'customer',
                        'party_id' => 1,
                        'transaction_date' => '2025-12-02',
                        'base_currency_id' => 1,
                        'base_amount' => 150,
                        'exchange_rate' => 82,
                        'local_currency_id' => 2,
                        'local_amount' => 12300,
                        'voucher_type' => 'sale',
                        'voucher_no' => $this->vch('S7-B'),
                    ],
                    [
                        'party_type' => 'customer',
                        'party_id' => 1,
                        'transaction_date' => '2025-12-03',
                        'base_currency_id' => 1,
                        'base_amount' => 120,
                        'exchange_rate' => 84,
                        'local_currency_id' => 2,
                        'local_amount' => 10080,
                        'voucher_type' => 'receipt',
                        'voucher_no' => $this->vch('R7-1'),
                    ],
                    [
                        'party_type' => 'customer',
                        'party_id' => 1,
                        'transaction_date' => '2025-12-04',
                        'base_currency_id' => 1,
                        'base_amount' => 130,
                        'exchange_rate' => 81,
                        'local_currency_id' => 2,
                        'local_amount' => 10530,
                        'voucher_type' => 'receipt',
                        'voucher_no' => $this->vch('R7-2'),
                    ],
                ];

            // 8) MULTI PURCHASE + MULTI PAYMENT (FIFO)
            case '8':
                return [
                    [
                        'party_type' => 'supplier',
                        'party_id' => 1,
                        'transaction_date' => '2025-12-01',
                        'base_currency_id' => 1,
                        'base_amount' => 200,
                        'exchange_rate' => 79,
                        'local_currency_id' => 2,
                        'local_amount' => 15800,
                        'voucher_type' => 'purchase',
                        'voucher_no' => $this->vch('P8-A'),
                    ],
                    [
                        'party_type' => 'supplier',
                        'party_id' => 1,
                        'transaction_date' => '2025-12-02',
                        'base_currency_id' => 1,
                        'base_amount' => 180,
                        'exchange_rate' => 81,
                        'local_currency_id' => 2,
                        'local_amount' => 14580,
                        'voucher_type' => 'purchase',
                        'voucher_no' => $this->vch('P8-B'),
                    ],
                    [
                        'party_type' => 'supplier',
                        'party_id' => 1,
                        'transaction_date' => '2025-12-03',
                        'base_currency_id' => 1,
                        'base_amount' => 150,
                        'exchange_rate' => 80,
                        'local_currency_id' => 2,
                        'local_amount' => 12000,
                        'voucher_type' => 'payment',
                        'voucher_no' => $this->vch('PAY8-1'),
                    ],
                    [
                        'party_type' => 'supplier',
                        'party_id' => 1,
                        'transaction_date' => '2025-12-04',
                        'base_currency_id' => 1,
                        'base_amount' => 230,
                        'exchange_rate' => 82,
                        'local_currency_id' => 2,
                        'local_amount' => 18860,
                        'voucher_type' => 'payment',
                        'voucher_no' => $this->vch('PAY8-2'),
                    ],
                ];

            // 9) OVER RECEIPT → ADVANCE CREATED
            case '9':
                return [
                    [
                        'party_type' => 'customer',
                        'party_id' => 1,
                        'transaction_date' => '2025-12-01',
                        'base_currency_id' => 1,
                        'base_amount' => 100,
                        'exchange_rate' => 80,
                        'local_currency_id' => 2,
                        'local_amount' => 8000,
                        'voucher_type' => 'sale',
                        'voucher_no' => $this->vch('S9'),
                    ],
                    [
                        'party_type' => 'customer',
                        'party_id' => 1,
                        'transaction_date' => '2025-12-02',
                        'base_currency_id' => 1,
                        'base_amount' => 150, // over receipt
                        'exchange_rate' => 85,
                        'local_currency_id' => 2,
                        'local_amount' => 12750,
                        'voucher_type' => 'receipt',
                        'voucher_no' => $this->vch('R9'),
                    ],
                ];

            // 10) OVER PAYMENT → ADVANCE CREATED
            case '10':
                return [
                    [
                        'party_type' => 'supplier',
                        'party_id' => 1,
                        'transaction_date' => '2025-12-01',
                        'base_currency_id' => 1,
                        'base_amount' => 120,
                        'exchange_rate' => 79,
                        'local_currency_id' => 2,
                        'local_amount' => 9480,
                        'voucher_type' => 'purchase',
                        'voucher_no' => $this->vch('P10'),
                    ],
                    [
                        'party_type' => 'supplier',
                        'party_id' => 1,
                        'transaction_date' => '2025-12-02',
                        'base_currency_id' => 1,
                        'base_amount' => 200, // over payment
                        'exchange_rate' => 78,
                        'local_currency_id' => 2,
                        'local_amount' => 15600,
                        'voucher_type' => 'payment',
                        'voucher_no' => $this->vch('PAY10'),
                    ],
                ];

            // 11 BACKDATED RECEIPT
            case '11':
                return [
                    [
                        'party_type' => 'customer',
                        'party_id' => 1,
                        'transaction_date' => '2025-12-04',
                        'base_currency_id' => 1,
                        'base_amount' => 150,
                        'exchange_rate' => 80,
                        'local_currency_id' => 2,
                        'local_amount' => 12000,
                        'voucher_type' => 'sale',
                        'voucher_no' => $this->vch('S11'),
                    ],
                    [
                        'party_type' => 'customer',
                        'party_id' => 1,
                        'transaction_date' => '2025-12-02', // backdated
                        'base_currency_id' => 1,
                        'base_amount' => 100,
                        'exchange_rate' => 84,
                        'local_currency_id' => 2,
                        'local_amount' => 8400,
                        'voucher_type' => 'receipt',
                        'voucher_no' => $this->vch('R11'),
                    ],
                ];

            // 12 BACKDATED PAYMENT
            case '12':
                return [
                    [
                        'party_type' => 'supplier',
                        'party_id' => 1,
                        'transaction_date' => '2025-12-04',
                        'base_currency_id' => 1,
                        'base_amount' => 200,
                        'exchange_rate' => 82,
                        'local_currency_id' => 2,
                        'local_amount' => 16400,
                        'voucher_type' => 'purchase',
                        'voucher_no' => $this->vch('P12'),
                    ],
                    [
                        'party_type' => 'supplier',
                        'party_id' => 1,
                        'transaction_date' => '2025-12-01', // backdated
                        'base_currency_id' => 1,
                        'base_amount' => 180,
                        'exchange_rate' => 80,
                        'local_currency_id' => 2,
                        'local_amount' => 14400,
                        'voucher_type' => 'payment',
                        'voucher_no' => $this->vch('PAY12'),
                    ],
                ];
        }

        return null;
    }
}
