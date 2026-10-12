<?php

namespace App\Services;

use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\InsufficientFundsException;
use App\Exceptions\InvalidTransferException;
use App\Models\Account;
use App\Models\LedgerEntry;
use App\Models\Transfer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class TransferService
{
    /**
     * @param string $amount положительное целое в минимальных единицах, строкой ("1500")
     * @throws InvalidTransferException
     * @throws InsufficientFundsException
     * @throws \App\Exceptions\IdempotencyConflictException
     */
    public function transfer(
        int    $fromAccountId,
        int    $toAccountId,
        string $amount,
        string $idempotencyKey,
    ): Transfer
    {
        if ($fromAccountId === $toAccountId) {
            throw new InvalidTransferException();
        }
        if (preg_match('/^[1-9][0-9]*$/', $amount) !== 1 || bccomp($amount, '0') < 0) {
            throw new InvalidTransferException(
                'Amount must be a positive integer string',
            );
        }
        try {
            $transfer = DB::transaction(function () use (
                $fromAccountId,
                $toAccountId,
                $amount,
                $idempotencyKey,
            ) {
                $accounts = $this->lockAccounts(
                    $fromAccountId,
                    $toAccountId,
                );

                /**
                 * @var Account $accountFrom
                 * @var Account $accountTo
                 */
                $accountFrom = $accounts->get($fromAccountId);
                $accountTo = $accounts->get($toAccountId);

                if (!$accountFrom || !$accountTo) {
                    throw new InvalidTransferException('Account not found');
                }
                if ($accountFrom->currency !== $accountTo->currency) {
                    throw new InvalidTransferException(
                        'Accounts have different currencies',
                    );
                }

                $existingTransfer = $this->findExistingTransfer(
                    $idempotencyKey,
                    $fromAccountId,
                    $toAccountId,
                    $amount,
                );

                if ($existingTransfer) {
                    return $existingTransfer;
                }

                $idempotencyTransfer = $this->findIdempotencyConflict($idempotencyKey);
                if ($idempotencyTransfer) {
                    throw new IdempotencyConflictException();
                }

                if (bccomp($amount, $accountFrom->balance, 0) > 0) {
                    throw new InsufficientFundsException();
                }

                $transfer = $this->createTransfer(
                    $fromAccountId,
                    $toAccountId,
                    $amount,
                    $idempotencyKey,
                );

                $this->applyBalances($accountFrom, $accountTo, $amount);

                $this->createLedgerEntries($transfer, $accountFrom, $accountTo, $amount);

                foreach ($accounts as $account) {
                    $account->save();
                }

                return $transfer;
            }, 5);
        } catch (UniqueConstraintViolationException $e) {
            $transfer = $this->findExistingTransfer(
                $idempotencyKey,
                $fromAccountId,
                $toAccountId,
                $amount,
            ) ?? throw new IdempotencyConflictException();
        }
        return $transfer;
    }

    private function lockAccounts(int $fromId, int $toId)
    {
        return Account::query()
            ->whereIn('id', [$fromId, $toId])
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    private function findExistingTransfer(
        string $key,
        int    $fromId,
        int    $toId,
        string $amount
    ): ?Transfer
    {
        return Transfer::query()
            ->where('idempotency_key', $key)
            ->where('from_account_id', $fromId)
            ->where('to_account_id', $toId)
            ->where('amount', $amount)
            ->first();
    }

    private function createTransfer(
        int    $fromId,
        int    $toId,
        string $amount,
        string $key,
    ): Transfer
    {
        return Transfer::create([
            'from_account_id' => $fromId,
            'to_account_id' => $toId,
            'amount' => $amount,
            'idempotency_key' => $key,
            'status' => 'completed',
            'request_hash' => hash(
                'sha256',
                "$fromId:$toId:$amount",
            ),
        ]);
    }

    private function applyBalances(
        Account $from,
        Account $to,
        string  $amount,
    ): void
    {
        $from->balance = bcsub($from->balance, $amount);
        $to->balance = bcadd($to->balance, $amount);
    }

    private function createLedgerEntries(
        Transfer $transfer,
        Account  $from,
        Account  $to,
        string   $amount,
    ): void
    {
        LedgerEntry::create([
            'account_id' => $from->id,
            'transfer_id' => $transfer->id,
            'amount' => '-' . $amount,   // минус строкой, не оператором
        ]);

        LedgerEntry::create([
            'account_id' => $to->id,
            'transfer_id' => $transfer->id,
            'amount' => $amount,
        ]);
    }

    private function findIdempotencyConflict(string $key): ?Transfer
    {
        return Transfer::query()
            ->where('idempotency_key', $key)
            ->first();
    }
}
