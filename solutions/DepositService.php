<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Deposit;
use App\Models\LedgerEntry;
use Illuminate\Support\Facades\DB;

class DepositService
{
    /**
     * @return array{deposit: Deposit, created: bool}
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException  неизвестный адрес
     * @throws \DomainException                                       повтор с другой суммой
     */
    public function handle(string $chain, string $txid, int $vout, string $address, string $amount, int $confirmations): array
    {
        $account = Account::where('deposit_address', $address)->firstOrFail();
        $required = config("wallet.confirmations.$chain");

        return DB::transaction(function () use ($chain, $txid, $vout, $account, $amount, $confirmations, $required) {
            $created = DB::table('deposits')->insertOrIgnore([
                'chain' => $chain, 'txid' => $txid, 'vout' => $vout,
                'account_id' => $account->id, 'amount' => $amount,
                'confirmations' => $confirmations, 'status' => 'pending',
                'created_at' => now(), 'updated_at' => now(),
            ]) === 1;

            // Лочим строку депозита: два одновременных вебхука не зачислят дважды.
            $deposit = Deposit::where(['chain' => $chain, 'txid' => $txid, 'vout' => $vout])->lockForUpdate()->firstOrFail();

            if (bccomp($deposit->amount, $amount, 0) !== 0) {
                throw new \DomainException('amount mismatch for known deposit');
            }

            // Подтверждения только растут (вебхуки могут прийти не по порядку).
            if ($confirmations > $deposit->confirmations) {
                $deposit->confirmations = $confirmations;
            }

            if ($deposit->status === 'pending' && $deposit->confirmations >= $required) {
                Account::where('id', $account->id)->lockForUpdate()->first();
                DB::table('accounts')->where('id', $account->id)->update(['balance' => DB::raw('balance + ' . $amount), 'updated_at' => now()]);
                LedgerEntry::create(['deposit_id' => $deposit->id, 'account_id' => $account->id, 'amount' => $amount]);
                $deposit->status = 'credited';
            }

            $deposit->save();

            return ['deposit' => $deposit, 'created' => $created];
        });
    }
}
