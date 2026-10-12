<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\LedgerEntry;
use App\Models\Transfer;
use App\Models\User;
use App\Services\TransferService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Настоящий параллелизм: несколько ПРОЦЕССОВ (fork) с отдельными подключениями к БД.
 *
 * Почему это нельзя сделать в обычном тесте с RefreshDatabase: тот оборачивает весь тест в одну
 * транзакцию на ОДНОМ подключении. Дети-процессы получают свои подключения и НЕ видят незакоммиченные
 * данные родителя, а PHPUnit выполняет тесты строго последовательно. Поэтому здесь данные коммитятся
 * по-настоящему и сами удаляются в tearDown.
 */
class TransferConcurrencyTest extends TestCase
{
    /** @var int[] */
    private array $accountIds = [];
    /** @var string[] */
    private array $keys = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            $this->markTestSkipped('нужны расширения pcntl и posix');
        }
        $this->artisan('migrate', ['--force' => true]); // схема есть, даже если другие тесты не запускались
    }

    protected function tearDown(): void
    {
        DB::table('ledger_entries')->whereIn('account_id', $this->accountIds)->delete();
        DB::table('transfers')->whereIn('idempotency_key', $this->keys)->delete();
        $userIds = DB::table('accounts')->whereIn('id', $this->accountIds)->pluck('user_id');
        DB::table('accounts')->whereIn('id', $this->accountIds)->delete();
        DB::table('users')->whereIn('id', $userIds)->delete();
        parent::tearDown();
    }

    private function account(string $balance = '1000000'): int
    {
        $user = User::factory()->create();
        $acc = Account::create(['user_id' => $user->id, 'currency' => 'USDT', 'balance' => $balance]);
        LedgerEntry::create(['account_id' => $acc->id, 'amount' => $balance]);

        return $this->accountIds[] = $acc->id;
    }

    /**
     * Запускает все вызовы одновременно, каждый в своём процессе.
     * Возвращает для каждого короткий результат: ok | conflict | domain | ИмяКласса исключения.
     *
     * @param array<int, array{0:int,1:int,2:string,3:string}> $calls [from, to, amount, key]
     * @return string[]
     */
    private function parallel(array $calls): array
    {
        DB::disconnect();               // дети не должны делить сокет родителя
        $children = [];
        foreach ($calls as $i => $call) {
            $file = tempnam(sys_get_temp_dir(), 'tc');
            $pid = pcntl_fork();
            if ($pid === 0) {
                try {
                    DB::reconnect();
                    app(TransferService::class)->transfer(...$call);
                    $result = 'ok';
                } catch (\App\Exceptions\IdempotencyConflictException) {
                    $result = 'conflict';
                } catch (\DomainException) {
                    $result = 'domain';
                } catch (\Throwable $e) {
                    $result = get_class($e);
                }
                file_put_contents($file, $result);
                // exit() запустил бы shutdown-обработчики PHPUnit в дочернем процессе - убиваем себя
                posix_kill(posix_getpid(), SIGKILL);
            }
            $children[$i] = [$pid, $file];
        }

        $results = [];
        foreach ($children as $i => [$pid, $file]) {
            pcntl_waitpid($pid, $status);
            $results[$i] = (string) file_get_contents($file) ?: 'no-result';
            @unlink($file);
        }
        DB::reconnect();

        return $results;
    }

    private function key(string $name): string
    {
        return $this->keys[] = $name . '-' . bin2hex(random_bytes(4));
    }

    public function test_same_key_same_params_parallel_creates_exactly_one_transfer(): void
    {
        [$a, $b] = [$this->account(), $this->account()];
        $key = $this->key('same');

        $results = $this->parallel(array_fill(0, 8, [$a, $b, '500', $key]));

        $this->assertSame(array_fill(0, 8, 'ok'), $results, 'повтор с теми же параметрами должен возвращать результат, а не падать');
        $this->assertSame(1, Transfer::where('idempotency_key', $key)->count());
        $this->assertSame('999500', (string) Account::find($a)->balance, 'списано должно быть один раз');
    }

    public function test_same_key_different_recipients_parallel_one_wins_other_is_conflict(): void
    {
        [$a, $b, $c] = [$this->account(), $this->account(), $this->account()];
        $key = $this->key('recipients');

        $results = $this->parallel([[$a, $b, '500', $key], [$a, $c, '500', $key]]);

        sort($results);
        $this->assertSame(['conflict', 'ok'], $results);
        $this->assertSame(1, Transfer::where('idempotency_key', $key)->count());
    }

    /** Ключ тот же, а счета-отправители разные: общих блокировок строк нет, спасает только unique-индекс. */
    public function test_same_key_different_senders_parallel_one_wins_other_is_conflict(): void
    {
        [$a, $b, $c, $d] = [$this->account(), $this->account(), $this->account(), $this->account()];

        // гонка недетерминирована: повторяем, чтобы окна точно пересеклись
        for ($i = 0; $i < 10; $i++) {
            $key = $this->key('senders');
            $results = $this->parallel([[$a, $b, '100', $key], [$c, $d, '100', $key]]);

            sort($results);
            $this->assertSame(['conflict', 'ok'], $results, "итерация $i: проигравший должен получить IdempotencyConflictException, а не сырое исключение БД");
            $this->assertSame(1, Transfer::where('idempotency_key', $key)->count());
        }
    }
}
