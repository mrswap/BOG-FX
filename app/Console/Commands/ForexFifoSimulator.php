<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\ForexService;
use Carbon\Carbon;

class ForexFifoSimulator extends Command
{
    protected $signature = 'forex:fifo-simulate {--input= : JSON file path or JSON string (array of transactions)} {--closing_rate= : Optional closing rate (applies per party if provided)}';
    protected $description = 'Simulate FIFO Forex matching for multiple parties (invoices/payments/receipts/sales) and print per-party ledger with realised & unrealised FX gain/loss.';

    public function handle()
    {
        $this->info("\n💱 Forex FIFO Simulator — Party Ledger + Realised/Unrealised FX\n");

        $raw = $this->option('input');
        $closingRateOption = $this->option('closing_rate');

        if ($raw) {
            // try file first
            if (file_exists($raw)) {
                $json = file_get_contents($raw);
            } else {
                $json = $raw;
            }

            $transactions = json_decode($json, true);
            if (!is_array($transactions)) {
                $this->error("Invalid JSON passed via --input. Expect array of transactions.");
                return 1;
            }
        } else {
            // Demo dataset matching your described behaviour
            $transactions = [
                // supplier/vendor entries (party_type = supplier, party_id = 1)
                // Invoice (purchase) created first: base 100 @80
                [
                    'party_type' => 'supplier',
                    'party_id' => 1,
                    'type' => 'purchase',        // purchase invoice (vendor bill)
                    'voucher_no' => 'P-100',
                    'base' => 100.0,
                    'rate' => 80.0,
                    'date' => '2025-11-01',
                ],
                // Payment later: payment of 200 @81
                [
                    'party_type' => 'supplier',
                    'party_id' => 1,
                    'type' => 'payment',         // payment to supplier
                    'voucher_no' => 'PM-200',
                    'base' => 200.0,
                    'rate' => 81.0,
                    'date' => '2025-11-02',
                ],

                // Example: customer prepayment scenario (party_type = customer, party_id = 2)
                [
                    'party_type' => 'customer',
                    'party_id' => 2,
                    'type' => 'receipt',         // receipt from customer (prepayment)
                    'voucher_no' => 'RC-50',
                    'base' => 50.0,
                    'rate' => 75.0,
                    'date' => '2025-11-01',
                ],
                [
                    'party_type' => 'customer',
                    'party_id' => 2,
                    'type' => 'sale',            // sale invoice that will consume receipt
                    'voucher_no' => 'S-70',
                    'base' => 70.0,
                    'rate' => 74.0,
                    'date' => '2025-11-03',
                ],
            ];
        }

        // Normalize and group by party (party_type + party_id)
        $byParty = [];
        foreach ($transactions as $t) {
            $partyType = $t['party_type'] ?? 'supplier';
            $partyId = $t['party_id'] ?? 0;
            $key = "{$partyType}:{$partyId}";

            // normalize fields
            $tx = [
                'party_type' => $partyType,
                'party_id' => $partyId,
                'type' => strtolower($t['type'] ?? 'payment'),
                'voucher_no' => $t['voucher_no'] ?? 'V-' . uniqid(),
                'base' => (float)($t['base'] ?? 0),
                'rate' => (float)($t['rate'] ?? 0),
                'date' => isset($t['date']) ? Carbon::parse($t['date']) : Carbon::now(),
            ];

            $byParty[$key][] = $tx;
        }

        $service = new ForexService();

        foreach ($byParty as $partyKey => $txs) {
            [$partyType, $partyId] = explode(':', $partyKey);
            $this->line("\n----------------------------------------------");
            $this->info("Party: {$partyType} (ID: {$partyId})");
            $this->line("----------------------------------------------");

            // Sort transactions by date asc, then by type stable ordering (to simulate voucher chronological)
            usort($txs, function ($a, $b) {
                if ($a['date']->eq($b['date'])) {
                    return 0;
                }
                return $a['date']->lt($b['date']) ? -1 : 1;
            });

            // Open positions arrays — each item keeps remaining_base & original info
            $openInvoices = [];   // invoices / sales / purchase -> need to be settled by payments/receipts
            $openPayments = [];   // payments / receipts -> need to be applied to invoices
            $ledger = [];         // sequence of ledger lines to display
            $totalRealised = 0.0;

            // process chronological
            foreach ($txs as $tx) {
                $type = $tx['type'];
                $base = round($tx['base'], 4);
                $rate = round($tx['rate'], 4);
                $date = $tx['date']->toDateString();
                $vno = $tx['voucher_no'];

                // classify invoice-like vs payment-like
                // For supplier: 'purchase' = invoice (we owe supplier), 'payment' = payment
                // For customer: 'sale' = invoice (customer owes), 'receipt' = receipt
                $isInvoice = in_array($type, ['purchase', 'sale']);
                $isPayment = in_array($type, ['payment', 'receipt']);

                // Create an open record
                $record = [
                    'party_type' => $tx['party_type'],
                    'party_id' => $tx['party_id'],
                    'type' => $type,
                    'voucher_no' => $vno,
                    'original_base' => $base,
                    'remaining_base' => $base,
                    'rate' => $rate,
                    'date' => $date,
                ];

                // Push into appropriate list, then attempt matching (FIFO)
                if ($isInvoice) {
                    // new invoice appears — attempt to match with existing open payments (prepayments)
                    $openInvoices[] = $record;
                    $ledger[] = $this->makeLedgerRow($record, '-', 'Open Invoice');

                    // try match with earliest payments (FIFO)
                    foreach ($openPayments as &$pay) {
                        if ($record['remaining_base'] <= 0) break;
                        if ($pay['remaining_base'] <= 0) continue;

                        $apply = min($record['remaining_base'], $pay['remaining_base']);
                        if ($apply <= 0) continue;

                        // realised gain/loss: (paymentRate - invoiceRate) * appliedBase
                        $realised = ForexService::realisedGainLoss($apply, $pay['rate'], $record['rate']);

                        // create ledger lines: portion of invoice settled + portion of payment applied
                        $ledger[] = [
                            'date' => $date,
                            'voucher_no' => $record['voucher_no'],
                            'type' => 'Invoice-Settled',
                            'rate' => $record['rate'],
                            'base' => $apply,
                            'local' => round($apply * $record['rate'], 4),
                            'gain_loss' => 0,
                            'status' => 'Invoice Settled (applied)'
                        ];

                        $ledger[] = [
                            'date' => $date,
                            'voucher_no' => $pay['voucher_no'],
                            'type' => 'Payment-Applied',
                            'rate' => $pay['rate'],
                            'base' => $apply,
                            'local' => round($apply * $pay['rate'], 4),
                            'gain_loss' => $realised,
                            'status' => 'Realised'
                        ];

                        $record['remaining_base'] -= $apply;
                        $pay['remaining_base'] -= $apply;
                        $totalRealised += $realised;
                    }
                    unset($pay);
                } else if ($isPayment) {
                    // new payment appears — attempt to match with existing open invoices (FIFO)
                    $openPayments[] = $record;
                    $ledger[] = $this->makeLedgerRow($record, '-', 'Open Payment');

                    foreach ($openInvoices as &$inv) {
                        if ($record['remaining_base'] <= 0) break;
                        if ($inv['remaining_base'] <= 0) continue;

                        $apply = min($inv['remaining_base'], $record['remaining_base']);
                        if ($apply <= 0) continue;

                        $realised = ForexService::realisedGainLoss($apply, $record['rate'], $inv['rate']);

                        $ledger[] = [
                            'date' => $date,
                            'voucher_no' => $inv['voucher_no'],
                            'type' => 'Invoice-Settled',
                            'rate' => $inv['rate'],
                            'base' => $apply,
                            'local' => round($apply * $inv['rate'], 4),
                            'gain_loss' => 0,
                            'status' => 'Invoice Settled (applied)'
                        ];

                        $ledger[] = [
                            'date' => $date,
                            'voucher_no' => $record['voucher_no'],
                            'type' => 'Payment-Applied',
                            'rate' => $record['rate'],
                            'base' => $apply,
                            'local' => round($apply * $record['rate'], 4),
                            'gain_loss' => $realised,
                            'status' => 'Realised'
                        ];

                        $inv['remaining_base'] -= $apply;
                        $record['remaining_base'] -= $apply;
                        $totalRealised += $realised;
                    }
                    unset($inv);

                    // if payment still has remaining (excess), it becomes unrealised advance (carry-forward)
                    if ($record['remaining_base'] > 0.000001) {
                        $ledger[] = [
                            'date' => $date,
                            'voucher_no' => $record['voucher_no'],
                            'type' => 'Payment-Unrealised',
                            'rate' => $record['rate'],
                            'base' => $record['remaining_base'],
                            'local' => round($record['remaining_base'] * $record['rate'], 4),
                            'gain_loss' => 0,
                            'status' => 'Unrealised (Advance)'
                        ];
                    }
                }
            } // end foreach txs

            // After processing all transactions, compute overall unrealised for open items based on closing rate (if provided)
            $closingRate = $closingRateOption ? (float)$closingRateOption : null;

            // If not provided, check if transactions include a manual 'closing_rate' per party in input (not implemented above).
            // We'll compute unrealised for any remaining open payments/invoices using closingRate if provided; otherwise show open with current recorded rate.

            $totalUnrealised = 0.0;
            if ($closingRate) {
                // For open payments (advances), unrealised = (closingRate - paymentRate) * remaining_base
                foreach ($openPayments as $p) {
                    if ($p['remaining_base'] <= 0) continue;
                    $u = round(($closingRate - $p['rate']) * $p['remaining_base'], 4);
                    $totalUnrealised += $u;
                    $ledger[] = [
                        'date' => Carbon::now()->toDateString(),
                        'voucher_no' => $p['voucher_no'],
                        'type' => 'Unrealised (advance)',
                        'rate' => $p['rate'],
                        'base' => $p['remaining_base'],
                        'local' => round($p['remaining_base'] * $closingRate, 4),
                        'gain_loss' => $u,
                        'status' => 'Unrealised (computed)'
                    ];
                }
                // For open invoices (dues), unrealised = (invoiceRate - closingRate) * remaining_base ? depends on accounting sign conv.
                foreach ($openInvoices as $inv) {
                    if ($inv['remaining_base'] <= 0) continue;
                    // If invoice open (we owe or they owe), unrealised = (closingRate - invoiceRate) * remaining_base
                    $u = round(($closingRate - $inv['rate']) * $inv['remaining_base'], 4);
                    $totalUnrealised += $u;
                    $ledger[] = [
                        'date' => Carbon::now()->toDateString(),
                        'voucher_no' => $inv['voucher_no'],
                        'type' => 'Unrealised (open invoice)',
                        'rate' => $inv['rate'],
                        'base' => $inv['remaining_base'],
                        'local' => round($inv['remaining_base'] * $closingRate, 4),
                        'gain_loss' => $u,
                        'status' => 'Unrealised (computed)'
                    ];
                }
            } else {
                // No closing rate provided — show existing open positions (unrealised shown as 0 until user supplies monthly average)
                foreach ($openPayments as $p) {
                    if ($p['remaining_base'] <= 0) continue;
                    $ledger[] = [
                        'date' => Carbon::now()->toDateString(),
                        'voucher_no' => $p['voucher_no'],
                        'type' => 'Open Payment (advance)',
                        'rate' => $p['rate'],
                        'base' => $p['remaining_base'],
                        'local' => round($p['remaining_base'] * $p['rate'], 4),
                        'gain_loss' => 0,
                        'status' => 'Open (unrealised)'
                    ];
                }
                foreach ($openInvoices as $inv) {
                    if ($inv['remaining_base'] <= 0) continue;
                    $ledger[] = [
                        'date' => Carbon::now()->toDateString(),
                        'voucher_no' => $inv['voucher_no'],
                        'type' => 'Open Invoice (due)',
                        'rate' => $inv['rate'],
                        'base' => $inv['remaining_base'],
                        'local' => round($inv['remaining_base'] * $inv['rate'], 4),
                        'gain_loss' => 0,
                        'status' => 'Open (unrealised)'
                    ];
                }
            }

            // Print ledger table (compact)
            // --- SAFE LEDGER PRINTING SECTION ---
            $rows = [];
            foreach ($ledger as $i => $r) {
                // ensure numeric values before formatting
                $rate = is_numeric($r['rate']) ? number_format((float)$r['rate'], 4) : $r['rate'];
                $base = is_numeric($r['base']) ? number_format((float)$r['base'], 4) : $r['base'];
                $local = is_numeric($r['local']) ? number_format((float)$r['local'], 4) : $r['local'];
                $gainLoss = is_numeric($r['gain_loss']) ? number_format((float)$r['gain_loss'], 4) : $r['gain_loss'];

                $rows[] = [
                    $i + 1,
                    $r['date'] ?? '-',
                    $r['voucher_no'] ?? '-',
                    $r['type'] ?? '-',
                    $rate,
                    $base,
                    $local,
                    $gainLoss,
                    $r['status'] ?? '-'
                ];
            }

            if (count($rows) > 0) {
                $this->table(
                    ['#', 'Date', 'Vch No', 'Line Type', 'Rate', 'Base', 'Local', 'Gain/Loss', 'Status'],
                    $rows
                );
            } else {
                $this->info("No ledger lines for this party.");
            }

            $this->info("📊 Total Realised (so far): " . number_format($totalRealised, 4));
            $this->info("📊 Total Unrealised (computed): " . number_format($totalUnrealised, 4));
            $this->line("");
            $this->info("Notes:");
            $this->line("- FIFO applied: payments/receipts applied to earliest open invoices (by date).");
            $this->line("- Prepayments/excess are carried forward as 'Unrealised (Advance)'.");
            $this->line("- Realised gain/loss computed as (PaymentRate - InvoiceRate) × appliedBase.");
            $this->line("- Unrealised computed vs provided closing rate (use --closing_rate to compute).");
            $this->line("");
        } // end foreach party

        $this->info("\nDone.\n");
        return 0;
    }

    protected function makeLedgerRow($record, $local = '-', $status = 'Open')
    {
        return [
            'date' => $record['date'],
            'voucher_no' => $record['voucher_no'],
            'type' => ucfirst($record['type']),
            'rate' => $record['rate'],
            'base' => $record['original_base'],
            'local' => $local,
            'gain_loss' => 0,
            'status' => $status,
        ];
    }
}
