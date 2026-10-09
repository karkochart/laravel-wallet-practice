<?php

namespace Tests\Support;

use App\Models\Account;
use App\Models\LedgerEntry;
use App\Models\User;

trait MakesAccounts
{
    /** Аккаунт с начальным балансом (и соответствующей проводкой, чтобы ledger сходился). */
    protected function makeAccount(string $currency = 'USDT', string $balance = '0', ?string $address = null): Account
    {
        $user = User::factory()->create();
        $account = Account::create([
            'user_id' => $user->id,
            'currency' => $currency,
            'balance' => $balance,
            'deposit_address' => $address ?? 'addr_' . $user->id . '_' . $currency,
        ]);
        if (bccomp($balance, '0') > 0) {
            LedgerEntry::create(['account_id' => $account->id, 'amount' => $balance]);
        }

        return $account;
    }
}
