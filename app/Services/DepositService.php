<?php

namespace App\Services;

use App\Models\Deposit;

class DepositService
{
    /**
     * @return array{deposit: Deposit, created: bool}
     */
    public function handle(string $chain, string $txid, int $vout, string $address, string $amount, int $confirmations): array
    {
        throw new \RuntimeException('TODO: задача 2, см. docblock в tests/Feature/DepositWebhookTest.php');
    }
}
