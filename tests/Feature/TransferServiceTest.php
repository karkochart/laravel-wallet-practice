<?php

namespace Tests\Feature;

use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\InsufficientFundsException;
use App\Exceptions\InvalidTransferException;
use App\Services\TransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MakesAccounts;
use Tests\TestCase;

/**
 * ЗАДАЧА 1 (перевод между аккаунтами). Реализовать App\Services\TransferService::transfer().
 * Цель: все тесты зелёные. Время: 60-90 минут. Гуглить можно только документацию.
 *
 * Требования:
 *  - сумма - строка с положительным целым ("1500"), иначе InvalidTransferException
 *  - нельзя перевести самому себе, между разными валютами, на несуществующий аккаунт (InvalidTransferException)
 *  - нельзя уйти в минус (InsufficientFundsException), ничего не должно записаться
 *  - идемпотентность: тот же ключ + те же параметры = тот же Transfer, повторного списания нет
 *  - тот же ключ + другие параметры = IdempotencyConflictException
 *  - успешный перевод = 2 проводки в ledger_entries (минус у отправителя, плюс у получателя) и status = completed
 *  - переводы A->B и B->A одновременно не должны ловить deadlock (подсказка: порядок блокировок)
 */
class TransferServiceTest extends TestCase
{
    use RefreshDatabase, MakesAccounts;

    private function svc(): TransferService
    {
        return app(TransferService::class);
    }

    public function test_successful_transfer_moves_money_and_writes_two_ledger_entries(): void
    {
        $a = $this->makeAccount('USDT', '1000');
        $b = $this->makeAccount('USDT', '50');

        $t = $this->svc()->transfer($a->id, $b->id, '300', 'key-1');

        $this->assertSame('completed', $t->status);
        $this->assertSame('700', (string) $a->fresh()->balance);
        $this->assertSame('350', (string) $b->fresh()->balance);
        $this->assertSame(2, DB::table('ledger_entries')->where('transfer_id', $t->id)->count());
        $this->assertSame('0', (string) DB::table('ledger_entries')->where('transfer_id', $t->id)->sum('amount'));
    }

    public function test_insufficient_funds_changes_nothing(): void
    {
        $a = $this->makeAccount('USDT', '100');
        $b = $this->makeAccount('USDT', '0');

        try {
            $this->svc()->transfer($a->id, $b->id, '101', 'key-2');
            $this->fail('expected InsufficientFundsException');
        } catch (InsufficientFundsException) {
        }

        $this->assertSame('100', (string) $a->fresh()->balance);
        $this->assertSame('0', (string) $b->fresh()->balance);
        $this->assertSame(0, DB::table('transfers')->count());
    }

    public function test_exact_balance_can_be_transferred(): void
    {
        $a = $this->makeAccount('BTC', '100');
        $b = $this->makeAccount('BTC', '0');

        $this->svc()->transfer($a->id, $b->id, '100', 'key-3');

        $this->assertSame('0', (string) $a->fresh()->balance);
    }

    public function test_same_key_same_params_is_idempotent(): void
    {
        $a = $this->makeAccount('USDT', '1000');
        $b = $this->makeAccount('USDT', '0');

        $first = $this->svc()->transfer($a->id, $b->id, '300', 'key-4');
        $second = $this->svc()->transfer($a->id, $b->id, '300', 'key-4');

        $this->assertSame($first->id, $second->id);
        $this->assertSame('700', (string) $a->fresh()->balance);
        $this->assertSame(1, DB::table('transfers')->count());
        $this->assertSame(2, DB::table('ledger_entries')->where('transfer_id', $first->id)->count());
    }

    public function test_same_key_different_params_is_a_conflict(): void
    {
        $a = $this->makeAccount('USDT', '1000');
        $b = $this->makeAccount('USDT', '0');
        $this->svc()->transfer($a->id, $b->id, '300', 'key-5');

        $this->expectException(IdempotencyConflictException::class);
        $this->svc()->transfer($a->id, $b->id, '301', 'key-5');
    }

    #[DataProvider('invalidAmounts')]
    public function test_invalid_amount_is_rejected(string $amount): void
    {
        $a = $this->makeAccount('USDT', '1000');
        $b = $this->makeAccount('USDT', '0');

        $this->expectException(InvalidTransferException::class);
        $this->svc()->transfer($a->id, $b->id, $amount, 'key-6');
    }

    public static function invalidAmounts(): array
    {
        return [['0'], ['-5'], ['1.5'], ['abc'], [''], ['01'], ['1e3']];
    }

    public function test_cannot_transfer_to_self(): void
    {
        $a = $this->makeAccount('USDT', '1000');

        $this->expectException(InvalidTransferException::class);
        $this->svc()->transfer($a->id, $a->id, '10', 'key-7');
    }

    public function test_cannot_transfer_between_currencies(): void
    {
        $a = $this->makeAccount('USDT', '1000');
        $b = $this->makeAccount('BTC', '0');

        $this->expectException(InvalidTransferException::class);
        $this->svc()->transfer($a->id, $b->id, '10', 'key-8');
    }

    public function test_unknown_account_is_rejected_and_leaves_no_trace(): void
    {
        $a = $this->makeAccount('USDT', '1000');

        try {
            $this->svc()->transfer($a->id, 999999, '10', 'key-9');
            $this->fail('expected InvalidTransferException');
        } catch (InvalidTransferException) {
        }

        $this->assertSame(0, DB::table('transfers')->count());
        $this->assertSame('1000', (string) $a->fresh()->balance);
    }

    public function test_huge_uint256_amounts_do_not_lose_precision(): void
    {
        $big = '115792089237316195423570985008687907853269984665640564039457584007913129639935'; // 2^256-1
        $a = $this->makeAccount('ETH', $big);
        $b = $this->makeAccount('ETH', '0');

        $this->svc()->transfer($a->id, $b->id, $big, 'key-10');

        $this->assertSame('0', (string) $a->fresh()->balance);
        $this->assertSame($big, (string) $b->fresh()->balance);
    }
}
