<?php

namespace App\Services;

use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\InsufficientFundsException;
use App\Exceptions\InvalidTransferException;
use App\Models\Account;
use App\Models\LedgerEntry;
use App\Models\Transfer;
use Illuminate\Support\Facades\DB;

class TransferService
{
    public function transfer(int $fromAccountId, int $toAccountId, string $amount, string $idempotencyKey): Transfer
    {
        if (!preg_match('/^[1-9][0-9]*$/', $amount)) {
            throw new InvalidTransferException('amount must be a positive integer string');
        }
        if ($fromAccountId === $toAccountId) {
            throw new InvalidTransferException('cannot transfer to the same account');
        }

        $hash = hash('sha256', "$fromAccountId|$toAccountId|$amount");

        return DB::transaction(function () use ($fromAccountId, $toAccountId, $amount, $idempotencyKey, $hash) {
            // 1. Блокируем оба аккаунта В ПОРЯДКЕ id - иначе A->B и B->A дают deadlock.
            //    Заодно блокировка сериализует параллельные запросы с одним ключом по этим аккаунтам.
            $accounts = Account::whereIn('id', [$fromAccountId, $toAccountId])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $from = $accounts->get($fromAccountId);
            $to = $accounts->get($toAccountId);
            if (!$from || !$to) {
                throw new InvalidTransferException('account not found');
            }

            // 2. Идемпотентность: проверяем ПОСЛЕ блокировки (повтор не должен упасть на "мало денег").
            $existing = Transfer::where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                if ($existing->request_hash !== $hash) {
                    throw new IdempotencyConflictException('idempotency key reused with different parameters');
                }

                return $existing;
            }

            if ($from->currency !== $to->currency) {
                throw new InvalidTransferException('currency mismatch');
            }
            if (bccomp($from->balance, $amount, 0) < 0) {
                throw new InsufficientFundsException('insufficient funds');
            }

            // Страховка: тот же ключ с другими аккаунтами мог прийти параллельно - сработает unique-индекс.
            $inserted = DB::table('transfers')->insertOrIgnore([
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $hash,
                'from_account_id' => $fromAccountId,
                'to_account_id' => $toAccountId,
                'amount' => $amount,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if ($inserted === 0) {
                throw new IdempotencyConflictException('idempotency key reused with different parameters');
            }

            // 3. Баланс меняем относительным UPDATE, проводки пишем парой.
            DB::table('accounts')->where('id', $fromAccountId)->update(['balance' => DB::raw('balance - ' . $amount), 'updated_at' => now()]);
            DB::table('accounts')->where('id', $toAccountId)->update(['balance' => DB::raw('balance + ' . $amount), 'updated_at' => now()]);

            $transfer = Transfer::where('idempotency_key', $idempotencyKey)->firstOrFail();
            LedgerEntry::create(['transfer_id' => $transfer->id, 'account_id' => $fromAccountId, 'amount' => '-' . $amount]);
            LedgerEntry::create(['transfer_id' => $transfer->id, 'account_id' => $toAccountId, 'amount' => $amount]);

            $transfer->update(['status' => 'completed']);

            return $transfer->refresh();
        });
    }
}
