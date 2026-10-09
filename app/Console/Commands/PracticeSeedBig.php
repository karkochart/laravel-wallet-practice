<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PracticeSeedBig extends Command
{
    protected $signature = 'practice:seed-big {--users=50000} {--transfers=500000} {--deposits=300000} {--force}';
    protected $description = 'ОЧИЩАЕТ БД и заливает большой датасет для SQL-тренировки (generate_series). Только dev.';

    public function handle(): int
    {
        if (!$this->option('force') && !$this->confirm('Это TRUNCATE users/accounts/transfers/deposits/ledger_entries. Продолжить?')) {
            return self::FAILURE;
        }
        $users = (int) $this->option('users');
        $transfers = (int) $this->option('transfers');
        $deposits = (int) $this->option('deposits');

        $step = fn (string $name, string $sql, array $b = []) => $this->line(sprintf('  %-34s %5.1fs', $name, $this->timed(fn () => DB::statement($sql, $b))));

        // индексы из задания про EXPLAIN сбрасываем, чтобы датасет всегда стартовал в "плохом" состоянии
        DB::statement('DROP INDEX IF EXISTS ledger_entries_account_id_id_idx');
        DB::statement('DROP INDEX IF EXISTS ledger_entries_created_at_idx');
        DB::statement('TRUNCATE ledger_entries, deposits, transfers, accounts, users RESTART IDENTITY CASCADE');

        $step('users', "INSERT INTO users (name,email,password,created_at,updated_at)
            SELECT 'user'||g, 'user'||g||'@example.com', 'x', now() - (random()*400 || ' days')::interval, now()
            FROM generate_series(1, ?) g", [$users]);

        $step('accounts (BTC/ETH/USDT each)', "INSERT INTO accounts (user_id,currency,balance,deposit_address,created_at,updated_at)
            SELECT u.id, c.cur, 0, md5(u.id || c.cur), u.created_at, u.created_at
            FROM users u CROSS JOIN (VALUES ('BTC'),('ETH'),('USDT')) c(cur)");

        $step('initial funding ledger entries', "INSERT INTO ledger_entries (account_id, amount, created_at)
            SELECT id, 1000000000000, created_at FROM accounts");

        $step('transfers', "INSERT INTO transfers (idempotency_key,request_hash,from_account_id,to_account_id,amount,status,created_at,updated_at)
            SELECT gen_random_uuid()::text, md5(random()::text) || md5(random()::text), fa.id, ta.id,
                   t.amt, t.st, t.ts, t.ts
            FROM (
                SELECT 1 + floor(random() * ?)::int AS u1, 1 + floor(random() * ?)::int AS u2,
                       (ARRAY['BTC','ETH','USDT'])[1 + floor(random()*3)::int] AS cur,
                       1 + floor(random() * 1000000)::bigint AS amt,
                       CASE WHEN random() < 0.97 THEN 'completed' ELSE 'failed' END AS st,
                       now() - (random() * 365 || ' days')::interval AS ts
                FROM generate_series(1, ?)
            ) t
            JOIN accounts fa ON fa.user_id = t.u1 AND fa.currency = t.cur
            JOIN accounts ta ON ta.user_id = t.u2 AND ta.currency = t.cur
            WHERE t.u1 <> t.u2", [$users, $users, $transfers]);

        $step('ledger entries for transfers', "INSERT INTO ledger_entries (transfer_id, account_id, amount, created_at)
            SELECT id, from_account_id, -amount, created_at FROM transfers WHERE status = 'completed'
            UNION ALL
            SELECT id, to_account_id, amount, created_at FROM transfers WHERE status = 'completed'");

        $step('balances from ledger', 'UPDATE accounts a SET balance = s.total
            FROM (SELECT account_id, SUM(amount) AS total FROM ledger_entries GROUP BY account_id) s WHERE s.account_id = a.id');

        $step('deposits', "INSERT INTO deposits (chain,txid,vout,account_id,amount,confirmations,status,created_at,updated_at)
            SELECT ch, md5(g::text || ch), floor(random()*3)::int, 1 + floor(random() * ?)::int,
                   1 + floor(random() * 5000000)::bigint, conf,
                   CASE WHEN conf >= CASE ch WHEN 'btc' THEN 3 WHEN 'eth' THEN 12 ELSE 19 END THEN 'credited' ELSE 'pending' END,
                   ts, ts
            FROM (
                SELECT g, (ARRAY['btc','eth','tron'])[1 + floor(random()*3)::int] AS ch,
                       floor(random() * 40)::int AS conf, now() - (random() * 365 || ' days')::interval AS ts
                FROM generate_series(1, ?) g
            ) d", [$users * 3, $deposits]);

        // Намеренно портим 25 аккаунтов - материал для задания "сверка балансов".
        $step('corrupt 25 balances (for drill #1)', 'UPDATE accounts SET balance = balance + 1 WHERE id IN (SELECT id FROM accounts ORDER BY random() LIMIT 25)');
        DB::statement('ANALYZE');

        foreach (['users', 'accounts', 'transfers', 'ledger_entries', 'deposits'] as $t) {
            $this->line(sprintf('  %-16s %s rows', $t, number_format(DB::table($t)->count())));
        }

        return self::SUCCESS;
    }

    private function timed(callable $fn): float
    {
        $t = microtime(true);
        $fn();

        return microtime(true) - $t;
    }
}
