<?php

namespace App\Crypto;

class Chain
{
    public static function erc20TransferData(string $toAddress, string $amount): string
    {
        $addr = strtolower(preg_replace('/^0x/i', '', $toAddress));
        if (!preg_match('/^[0-9a-f]{40}$/', $addr) || !preg_match('/^(0|[1-9][0-9]*)$/', $amount)) {
            throw new \InvalidArgumentException('bad address or amount');
        }
        $hex = gmp_strval(gmp_init($amount, 10), 16);
        if (strlen($hex) > 64) {
            throw new \InvalidArgumentException('amount exceeds uint256');
        }

        return '0xa9059cbb' . str_pad($addr, 64, '0', STR_PAD_LEFT) . str_pad($hex, 64, '0', STR_PAD_LEFT);
    }

    public static function btcFee(array $inputs, array $outputs): string
    {
        $fee = bcsub(array_reduce($inputs, 'bcadd', '0'), array_reduce($outputs, 'bcadd', '0'));
        if (bccomp($fee, '0') < 0) {
            throw new \InvalidArgumentException('outputs exceed inputs');
        }

        return $fee;
    }

    public static function btcVsizeP2wpkh(int $inputs, int $outputs): int
    {
        return (int) ceil(10.5 + 68 * $inputs + 31 * $outputs);
    }
}
