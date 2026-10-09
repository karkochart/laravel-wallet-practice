<?php

namespace App\Services;

use App\Models\Account;
use App\Models\LedgerEntry;
use App\Models\Transfer;
use Illuminate\Support\Facades\DB;

/**
 * НАМЕРЕННО СЛОМАННАЯ версия - для `php artisan practice:race --naive`.
 * Читает баланс без блокировки, пишет абсолютное значение (lost update), идемпотентности нет.
 * Найдите все баги и объясните, почему race-команда показывает расхождения.
 */
class NaiveTransferService
{
    public function transfer(int $from, int $to, string $amount, string $key): Transfer
    {
        return DB::transaction(function () use ($from, $to, $amount, $key) {
            $a = Account::find($from);
            $b = Account::find($to);
            if (bccomp($a->balance, $amount) < 0) {
                throw new \DomainException('insufficient funds');
            }
            usleep(random_int(0, 2000)); // "бизнес-логика", окно для гонки
            $a->update(['balance' => bcsub($a->balance, $amount)]);
            $b->update(['balance' => bcadd($b->balance, $amount)]);

            $t = Transfer::create([
                'idempotency_key' => $key, 'request_hash' => hash('sha256', $key),
                'from_account_id' => $from, 'to_account_id' => $to,
                'amount' => $amount, 'status' => 'completed',
            ]);
            LedgerEntry::create(['transfer_id' => $t->id, 'account_id' => $from, 'amount' => '-' . $amount]);
            LedgerEntry::create(['transfer_id' => $t->id, 'account_id' => $to, 'amount' => $amount]);

            return $t;
        });
    }
}
