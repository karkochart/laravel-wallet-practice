<?php

namespace Tests\Unit;

use App\Crypto\Chain;
use PHPUnit\Framework\TestCase;

/**
 * ЗАДАЧА 3 (расчёты вокруг транзакций, без сети). Реализовать App\Crypto\Chain.
 * Это ровно то, о чём спросит вакансия: «как отправить транзакцию в Ethereum» и «входы и выходы Bitcoin».
 *
 *  erc20TransferData($to, $amount): calldata для transfer(address,uint256):
 *     "0x" + селектор a9059cbb + адрес, дополненный нулями до 32 байт + сумма в hex до 32 байт.
 *     Сумма - десятичная СТРОКА (uint256 не влезает в int). Некорректный адрес/сумма -> InvalidArgumentException.
 *  btcFee($inputs, $outputs): комиссия = сумма входов - сумма выходов (в сатоши, строки).
 *     Отдельного поля fee в транзакции нет! Выходы больше входов -> InvalidArgumentException.
 *  btcVsizeP2wpkh($in, $out): vsize = ceil(10.5 + 68*in + 31*out) - оценка для нативных SegWit (bech32) входов/выходов.
 */
class ChainTest extends TestCase
{
    public function test_erc20_calldata_for_one_usdt(): void
    {
        $data = Chain::erc20TransferData('0x1111111111111111111111111111111111111111', '1000000'); // 1 USDT (6 decimals)

        $this->assertSame(
            '0xa9059cbb'
            . '0000000000000000000000001111111111111111111111111111111111111111'
            . '00000000000000000000000000000000000000000000000000000000000f4240',
            $data,
        );
        $this->assertSame(2 + 8 + 64 + 64, strlen($data));
    }

    public function test_erc20_calldata_accepts_mixed_case_address_and_zero_amount(): void
    {
        $data = Chain::erc20TransferData('0xAbCdEf0123456789aBcDeF0123456789abcdef01', '0');

        $this->assertStringContainsString('abcdef0123456789abcdef0123456789abcdef01', $data);
        $this->assertStringEndsWith(str_repeat('0', 64), $data);
    }

    public function test_erc20_calldata_handles_uint256_max(): void
    {
        $max = '115792089237316195423570985008687907853269984665640564039457584007913129639935';

        $data = Chain::erc20TransferData('0x1111111111111111111111111111111111111111', $max);

        $this->assertStringEndsWith(str_repeat('f', 64), $data);
    }

    public function test_erc20_calldata_rejects_bad_input(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Chain::erc20TransferData('0x123', '1');
    }

    public function test_erc20_calldata_rejects_overflow_and_garbage_amounts(): void
    {
        foreach (['115792089237316195423570985008687907853269984665640564039457584007913129639936', '-1', '1.5', 'abc'] as $bad) {
            try {
                Chain::erc20TransferData('0x1111111111111111111111111111111111111111', $bad);
                $this->fail("expected exception for amount $bad");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_btc_fee_is_inputs_minus_outputs(): void
    {
        // вход 0.001 BTC; платим 60 000 сатоши, сдача 39 000 -> комиссия 1 000
        $this->assertSame('1000', Chain::btcFee(['100000'], ['60000', '39000']));
    }

    public function test_btc_forgotten_change_output_means_huge_fee(): void
    {
        // классическая ошибка: нет change-выхода - вся "сдача" уходит майнеру
        $this->assertSame('40000', Chain::btcFee(['100000'], ['60000']));
    }

    public function test_btc_outputs_exceeding_inputs_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Chain::btcFee(['1000'], ['2000']);
    }

    public function test_btc_vsize_estimate(): void
    {
        $this->assertSame(141, Chain::btcVsizeP2wpkh(1, 2));   // ceil(10.5 + 68 + 62)
        $this->assertSame(209, Chain::btcVsizeP2wpkh(2, 2));   // ceil(10.5 + 136 + 62)
    }
}
