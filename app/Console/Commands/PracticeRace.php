<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Services\NaiveTransferService;
use App\Services\TransferService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PracticeRace extends Command
{
    protected $signature = 'practice:race {--workers=8} {--iterations=100} {--accounts=4} {--naive : use deliberately broken NaiveTransferService}';
    protected $description = 'Параллельные переводы в реальной БД (fork) + проверка инвариантов денег';

    public function handle(): int
    {
        if (!function_exists('pcntl_fork')) {
            $this->error('pcntl не установлен');

            return self::FAILURE;
        }

        $workers = (int) $this->option('workers');
        $iterations = (int) $this->option('iterations');
        $initial = '1000000';

        $ids = [];
        for ($i = 0; $i < (int) $this->option('accounts'); $i++) {
            $user = User::factory()->create();
            $acc = Account::create(['user_id' => $user->id, 'currency' => 'USDT', 'balance' => $initial]);
            LedgerEntry::create(['account_id' => $acc->id, 'amount' => $initial]);
            $ids[] = $acc->id;
        }
        $total = bcmul($initial, (string) count($ids));
        $run = Str::random(6);
        $this->info(sprintf('Старт: %d воркеров x %d переводов, %d аккаунтов, сервис: %s',
            $workers, $iterations, count($ids), $this->option('naive') ? 'NAIVE' : 'TransferService'));

        DB::disconnect(); // дети не должны делить сокет родителя
        $pids = [];
        for ($w = 0; $w < $workers; $w++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                exit($this->worker($ids, $iterations, "$run-$w"));
            }
            $pids[] = $pid;
        }
        $bad = 0;
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $bad += pcntl_wexitstatus($status);
        }
        DB::reconnect();

        return $this->verify($ids, $total, $bad);
    }

    private function worker(array $ids, int $iterations, string $tag): int
    {
        DB::reconnect();
        $svc = $this->option('naive') ? new NaiveTransferService() : app(TransferService::class);
        $errors = 0;
        for ($i = 0; $i < $iterations; $i++) {
            [$from, $to] = array_rand(array_flip($ids), 2);
            try {
                $svc->transfer($from, $to, (string) random_int(1, 5000), "$tag-$i");
            } catch (\DomainException) {
                // нормальные бизнес-отказы (мало денег) не считаем
            } catch (\Throwable $e) {
                $errors++;
                fwrite(STDERR, '[' . $tag . '] ' . get_class($e) . ': ' . Str::limit($e->getMessage(), 120) . "\n");
            }
        }

        return min($errors, 255);
    }

    private function verify(array $ids, string $expectedTotal, int $unexpectedErrors): int
    {
        $ok = true;
        $sumBalances = (string) DB::table('accounts')->whereIn('id', $ids)->sum('balance');
        $negative = DB::table('accounts')->whereIn('id', $ids)->where('balance', '<', 0)->count();
        $mismatch = DB::table('accounts as a')
            ->selectRaw('a.id, a.balance, COALESCE(SUM(l.amount),0) AS ledger')
            ->leftJoin('ledger_entries as l', 'l.account_id', '=', 'a.id')
            ->whereIn('a.id', $ids)->groupBy('a.id', 'a.balance')
            ->havingRaw('a.balance <> COALESCE(SUM(l.amount),0)')->get();
        $unbalancedTransfers = DB::table('ledger_entries')->whereIn('account_id', $ids)->whereNotNull('transfer_id')
            ->select('transfer_id')->groupBy('transfer_id')->havingRaw('SUM(amount) <> 0')->get()->count();

        $check = function (string $name, bool $pass, string $detail = '') use (&$ok) {
            $ok = $ok && $pass;
            $this->line(sprintf('  [%s] %s %s', $pass ? ' OK ' : 'FAIL', $name, $detail));
        };

        $this->newLine();
        $check('сумма балансов не изменилась', bccomp($sumBalances, $expectedTotal) === 0, "ожидали $expectedTotal, получили $sumBalances");
        $check('нет отрицательных балансов', $negative === 0, "отрицательных: $negative");
        $check('balance == сумма проводок', $mismatch->isEmpty(), 'расхождений: ' . $mismatch->count());
        $check('каждый перевод в ledger суммируется в 0', $unbalancedTransfers === 0, "битых переводов: $unbalancedTransfers");
        $check('нет неожиданных исключений (deadlock и т.п.)', $unexpectedErrors === 0, "ошибок: $unexpectedErrors");

        $this->newLine();
        $ok ? $this->info('ИТОГ: инварианты сохранены') : $this->error('ИТОГ: деньги потеряны/созданы или есть ошибки');

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
