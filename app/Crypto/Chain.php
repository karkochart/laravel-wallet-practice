<?php

namespace App\Crypto;

class Chain
{
    public static function erc20TransferData(string $toAddress, string $amount): string
    {
        throw new \RuntimeException('TODO: задача 3, см. docblock в tests/Unit/ChainTest.php');
    }

    public static function btcFee(array $inputs, array $outputs): string
    {
        throw new \RuntimeException('TODO');
    }

    public static function btcVsizeP2wpkh(int $inputs, int $outputs): int
    {
        throw new \RuntimeException('TODO');
    }
}
