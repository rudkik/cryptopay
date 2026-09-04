<?php

declare(strict_types=1);

namespace CryptoPay\Sdk\Tests;

use CryptoPay\Sdk\Dto\Balance;
use CryptoPay\Sdk\Dto\Balances;
use CryptoPay\Sdk\Dto\Invoice;
use CryptoPay\Sdk\Dto\Paginated;
use CryptoPay\Sdk\Dto\TokenPurchase;
use CryptoPay\Sdk\Dto\Transaction;
use PHPUnit\Framework\TestCase;

final class DtoTest extends TestCase
{
    public function test_transaction_from_array_keeps_amounts_as_strings(): void
    {
        $raw = [
            'id' => 'tx-1',
            'invoice_id' => 'inv-1',
            'network' => 'bsc',
            'tx_hash' => '0xabc',
            'log_index' => 0,
            'from_address' => '0x1',
            'to_address' => '0x2',
            'currency' => 'USDC',
            'contract_address' => '0x3',
            'amount' => '100.000000000000000000',
            'amount_raw' => '100000000000000000000',
            'block_number' => 119430112,
            'confirmations' => 15,
            'confirmations_required' => 15,
            'status' => 'confirmed',
            'explorer_url' => 'https://bscscan.com/tx/0xabc',
            'credited_at' => null,
            'created_at' => '2026-09-02T00:00:00+00:00',
        ];

        $transaction = Transaction::fromArray($raw);

        self::assertSame('100.000000000000000000', $transaction->amount);
        self::assertIsString($transaction->amount);
        self::assertTrue($transaction->isConfirmed());
        self::assertSame($raw, $transaction->toArray());
    }

    public function test_invoice_property_and_method_named_is_paid_coexist(): void
    {
        $invoice = Invoice::fromArray([
            'id' => 'inv-1',
            'type' => 'payment',
            'status' => 'paid',
            'is_paid' => true,
            'currency' => 'USDT',
            'network' => 'tron',
            'amount' => '1',
            'amount_received' => '1',
            'amount_confirmed' => '1',
            'metadata' => [],
            'transactions' => [],
        ]);

        // The property.
        self::assertTrue($invoice->isPaid);
        // The method.
        self::assertTrue($invoice->isPaid());
    }

    public function test_invoice_without_a_selected_pair_hydrates_nulls_and_needs_selection(): void
    {
        $invoice = Invoice::fromArray([
            'id' => 'inv-1',
            'type' => 'payment',
            'status' => 'pending',
            'is_paid' => false,
            'currency' => null,
            'network' => null,
            'amount' => '12.500000',
            'amount_received' => '0',
            'amount_confirmed' => '0',
            'address' => null,
            'qr_payload' => null,
            'payment_url' => 'http://localhost:8095/pay/inv-1',
            'selection_required' => true,
            'metadata' => [],
            'transactions' => [],
        ]);

        self::assertNull($invoice->currency);
        self::assertNull($invoice->network);
        self::assertNull($invoice->address);
        self::assertNull($invoice->qrPayload);
        self::assertSame('12.500000', $invoice->amount);
        self::assertTrue($invoice->selectionRequired);
        self::assertTrue($invoice->needsSelection());
        self::assertTrue($invoice->isPending());
    }

    public function test_invoice_selection_required_defaults_to_false_when_absent(): void
    {
        $invoice = Invoice::fromArray([
            'id' => 'inv-1',
            'type' => 'payment',
            'status' => 'pending',
            'is_paid' => false,
            'currency' => 'USDT',
            'network' => 'tron',
            'amount' => '1',
            'amount_received' => '0',
            'amount_confirmed' => '0',
            'metadata' => [],
            'transactions' => [],
        ]);

        self::assertFalse($invoice->selectionRequired);
        self::assertFalse($invoice->needsSelection());
    }

    public function test_token_purchase_currency_is_null_until_the_pair_is_selected(): void
    {
        $purchase = TokenPurchase::fromArray([
            'id' => 'tp-1',
            'invoice_id' => 'inv-1',
            'token_id' => 'tok-1',
            'customer_id' => 'user-42',
            'token_amount' => '100',
            'price_usd' => '1.5',
            'pay_amount' => '150',
            'currency' => null,
            'status' => 'pending',
        ]);

        self::assertNull($purchase->currency);
        self::assertFalse($purchase->isCompleted());
    }

    public function test_balances_total_helper_returns_zeros_for_unknown_currency(): void
    {
        $balances = Balances::fromArray([
            'data' => [
                ['currency' => 'USDT', 'network' => 'tron', 'available' => '10', 'pending' => '0'],
            ],
            'totals' => [
                'USDT' => ['available' => '10', 'pending' => '0'],
            ],
        ]);

        self::assertCount(1, $balances->data);
        self::assertInstanceOf(Balance::class, $balances->data[0]);
        self::assertSame(['available' => '10', 'pending' => '0'], $balances->total('USDT'));
        self::assertSame(['available' => '0', 'pending' => '0'], $balances->total('BTC'));
    }

    public function test_paginated_is_iterable_and_countable(): void
    {
        $paginated = new Paginated(
            data: ['a', 'b', 'c'],
            meta: ['current_page' => 2, 'last_page' => 2, 'per_page' => 3, 'total' => 6],
            links: [],
        );

        self::assertCount(3, $paginated);
        self::assertSame(3, $paginated->count());
        self::assertSame(2, $paginated->currentPage());
        self::assertSame(2, $paginated->lastPage());
        self::assertSame(6, $paginated->total());
        self::assertFalse($paginated->hasMorePages());

        self::assertSame(['a', 'b', 'c'], iterator_to_array($paginated));
    }

    public function test_invoice_status_helpers(): void
    {
        $expired = Invoice::fromArray(['id' => '1', 'type' => 'payment', 'status' => 'expired', 'is_paid' => false, 'currency' => 'USDT', 'network' => 'tron', 'amount' => '1', 'amount_received' => '0', 'amount_confirmed' => '0', 'metadata' => [], 'transactions' => []]);
        self::assertTrue($expired->isExpired());
        self::assertFalse($expired->isCancelled());

        $cancelled = Invoice::fromArray(['id' => '1', 'type' => 'payment', 'status' => 'cancelled', 'is_paid' => false, 'currency' => 'USDT', 'network' => 'tron', 'amount' => '1', 'amount_received' => '0', 'amount_confirmed' => '0', 'metadata' => [], 'transactions' => []]);
        self::assertTrue($cancelled->isCancelled());
    }
}
