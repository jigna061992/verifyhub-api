<?php

namespace Tests\Feature;

use App\Exceptions\InsufficientBalanceException;
use App\Models\WalletLedger;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesFixtures;
use Tests\TestCase;

// The wallet ledger is the heart of the money logic: every rule here protects real balances
class WalletServiceTest extends TestCase
{
    use RefreshDatabase, MakesFixtures;

    private function wallets(): WalletService
    {
        return app(WalletService::class);
    }

    public function test_credit_adds_money_and_writes_one_ledger_row(): void
    {
        $r = $this->makeRetailer();

        $this->wallets()->credit($r->id, 500, WalletService::topupReference(1), 'Top-up');

        $this->assertSame(['available' => 500.0, 'reserved' => 0.0], $this->wallet($r));
        $this->assertSame(1, WalletLedger::count());
    }

    public function test_same_reference_changes_the_wallet_only_once(): void
    {
        $r   = $this->makeRetailer();
        $ref = WalletService::topupReference(7);

        $this->wallets()->credit($r->id, 300, $ref, 'Top-up');
        $this->wallets()->credit($r->id, 300, $ref, 'Top-up again (double click)');

        $this->assertSame(300.0, $this->wallet($r)['available']);
        $this->assertSame(1, WalletLedger::count());
    }

    public function test_reserve_then_debit_spends_the_money(): void
    {
        $r = $this->makeRetailer(100);

        $this->wallets()->reserve($r->id, 5, WalletService::verificationReference(1, 'reserve'));
        $this->assertSame(['available' => 95.0, 'reserved' => 5.0], $this->wallet($r));

        $this->wallets()->debit($r->id, 5, WalletService::verificationReference(1, 'debit'));
        $this->assertSame(['available' => 95.0, 'reserved' => 0.0], $this->wallet($r));
    }

    public function test_reserve_then_release_returns_the_money(): void
    {
        $r = $this->makeRetailer(100);

        $this->wallets()->reserve($r->id, 8, WalletService::verificationReference(2, 'reserve'));
        $this->wallets()->release($r->id, 8, WalletService::verificationReference(2, 'release'));

        $this->assertSame(['available' => 100.0, 'reserved' => 0.0], $this->wallet($r));
    }

    public function test_reserve_fails_when_balance_is_too_low_and_changes_nothing(): void
    {
        $r = $this->makeRetailer(3);

        try {
            $this->wallets()->reserve($r->id, 5, WalletService::verificationReference(3, 'reserve'));
            $this->fail('Expected InsufficientBalanceException');
        } catch (InsufficientBalanceException) {
            // expected
        }

        $this->assertSame(['available' => 3.0, 'reserved' => 0.0], $this->wallet($r));
        $this->assertSame(0, WalletLedger::count());
    }

    public function test_reversal_cannot_take_more_than_available(): void
    {
        $r = $this->makeRetailer(10);

        $this->expectException(InsufficientBalanceException::class);

        $this->wallets()->reversal($r->id, 50, WalletService::adjustmentReference($r->id, '11111111-1111-4111-8111-111111111111'));
    }

    public function test_references_are_stable_and_different_per_step(): void
    {
        $this->assertSame(WalletService::verificationReference(5, 'debit'), WalletService::verificationReference(5, 'debit'));
        $this->assertNotSame(WalletService::verificationReference(5, 'debit'), WalletService::verificationReference(5, 'release'));
        $this->assertNotSame(WalletService::verificationReference(5, 'debit'), WalletService::verificationReference(6, 'debit'));
    }
}
