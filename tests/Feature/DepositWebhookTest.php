<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\MakesAccounts;
use Tests\TestCase;

/**
 * ЗАДАЧА 2 (вебхук депозита от блокчейн-сканера). POST /api/webhooks/deposit
 * Реализовать App\Services\DepositService + App\Http\Controllers\Api\DepositWebhookController.
 *
 * Тело: chain (btc|eth|tron), txid, vout (по умолчанию 0), address, amount (строка, целое > 0), confirmations
 * Подтверждений нужно: config('wallet.confirmations.<chain>') - btc 3, eth 12, tron 19.
 *
 * Ответы:
 *  - 201 первый раз увидели (chain, txid, vout); 200 повторный вебхук; тело {"status": "pending|credited", "confirmations": N}
 *  - 404 неизвестный address; 422 невалидное тело; 409 известный депозит пришёл с другой суммой
 * Правила:
 *  - зачисляем РОВНО ОДИН РАЗ, когда confirmations >= порога: баланс + проводка в ledger_entries
 *  - дубликаты вебхуков и вебхуки "не по порядку" (confirmations меньше, чем уже известно) не должны ничего ломать
 *  - ключ идемпотентности - (chain, txid, vout), в BTC одна транзакция может платить на несколько выходов
 */
class DepositWebhookTest extends TestCase
{
    use RefreshDatabase, MakesAccounts;

    private function hook(array $override = [])
    {
        return $this->postJson('/api/webhooks/deposit', array_merge([
            'chain' => 'btc', 'txid' => 'aaa111', 'vout' => 0,
            'address' => 'bc1qtest', 'amount' => '150000', 'confirmations' => 1,
        ], $override));
    }

    public function test_first_sight_is_pending_and_does_not_credit(): void
    {
        $acc = $this->makeAccount('BTC', '0', 'bc1qtest');

        $this->hook()->assertStatus(201)->assertJson(['status' => 'pending', 'confirmations' => 1]);

        $this->assertSame('0', (string) $acc->fresh()->balance);
        $this->assertSame(0, DB::table('ledger_entries')->count());
    }

    public function test_credited_once_when_threshold_reached(): void
    {
        $acc = $this->makeAccount('BTC', '0', 'bc1qtest');

        $this->hook(['confirmations' => 1])->assertStatus(201);
        $this->hook(['confirmations' => 2])->assertStatus(200)->assertJson(['status' => 'pending']);
        $this->hook(['confirmations' => 3])->assertStatus(200)->assertJson(['status' => 'credited']);

        $this->assertSame('150000', (string) $acc->fresh()->balance);
        $this->assertSame(1, DB::table('ledger_entries')->where('account_id', $acc->id)->count());
    }

    public function test_duplicate_webhooks_do_not_double_credit(): void
    {
        $acc = $this->makeAccount('BTC', '0', 'bc1qtest');

        $this->hook(['confirmations' => 5])->assertStatus(201)->assertJson(['status' => 'credited']);
        $this->hook(['confirmations' => 5])->assertStatus(200)->assertJson(['status' => 'credited']);
        $this->hook(['confirmations' => 6])->assertStatus(200)->assertJson(['status' => 'credited']);

        $this->assertSame('150000', (string) $acc->fresh()->balance);
        $this->assertSame(1, DB::table('ledger_entries')->count());
    }

    public function test_out_of_order_confirmations_never_decrease(): void
    {
        $this->makeAccount('BTC', '0', 'bc1qtest');

        $this->hook(['confirmations' => 2])->assertStatus(201);
        $this->hook(['confirmations' => 1])->assertStatus(200)->assertJson(['confirmations' => 2]);
    }

    public function test_thresholds_differ_per_chain(): void
    {
        $eth = $this->makeAccount('ETH', '0', '0xabc');

        $this->hook(['chain' => 'eth', 'address' => '0xabc', 'txid' => '0xtx1', 'confirmations' => 11])
            ->assertJson(['status' => 'pending']);
        $this->hook(['chain' => 'eth', 'address' => '0xabc', 'txid' => '0xtx1', 'confirmations' => 12])
            ->assertJson(['status' => 'credited']);

        $this->assertSame('150000', (string) $eth->fresh()->balance);
    }

    public function test_same_txid_different_vout_are_separate_deposits(): void
    {
        $acc = $this->makeAccount('BTC', '0', 'bc1qtest');

        $this->hook(['vout' => 0, 'confirmations' => 3])->assertStatus(201);
        $this->hook(['vout' => 1, 'confirmations' => 3])->assertStatus(201);

        $this->assertSame('300000', (string) $acc->fresh()->balance);
        $this->assertSame(2, DB::table('deposits')->count());
    }

    public function test_unknown_address_is_404(): void
    {
        $this->hook(['address' => 'nobody'])->assertStatus(404);
        $this->assertSame(0, DB::table('deposits')->count());
    }

    public function test_known_deposit_with_different_amount_is_409(): void
    {
        $this->makeAccount('BTC', '0', 'bc1qtest');
        $this->hook()->assertStatus(201);

        $this->hook(['amount' => '999'])->assertStatus(409);
    }

    public function test_validation(): void
    {
        $this->makeAccount('BTC', '0', 'bc1qtest');

        $this->hook(['chain' => 'doge'])->assertStatus(422);
        $this->hook(['amount' => '1.5'])->assertStatus(422);
        $this->hook(['amount' => '0'])->assertStatus(422);
        $this->hook(['confirmations' => -1])->assertStatus(422);
        $this->postJson('/api/webhooks/deposit', [])->assertStatus(422);
    }
}
