<?php

namespace App\Services;

use App\Models\Transfer;

class TransferService
{
    /**
     * @param string $amount положительное целое в минимальных единицах, строкой ("1500")
     * @throws \App\Exceptions\InvalidTransferException
     * @throws \App\Exceptions\InsufficientFundsException
     * @throws \App\Exceptions\IdempotencyConflictException
     */
    public function transfer(int $fromAccountId, int $toAccountId, string $amount, string $idempotencyKey): Transfer
    {
        throw new \RuntimeException('TODO: задача 1, см. docblock в tests/Feature/TransferServiceTest.php');
    }
}
