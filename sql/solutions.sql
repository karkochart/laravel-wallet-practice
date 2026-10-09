-- Эталонные решения к sql/drills.md. Не подглядывайте, пока не попробовали сами.
-- Запуск целиком: docker compose exec -T db psql -U wallet -d wallet -f /sql/solutions.sql  (папка ./sql примонтирована)
\timing on
\pset pager off

\echo '=== 1. Сверка: balance != сумма проводок'
SELECT a.id, a.balance, COALESCE(SUM(l.amount), 0) AS ledger_sum, a.balance - COALESCE(SUM(l.amount), 0) AS diff
FROM accounts a
LEFT JOIN ledger_entries l ON l.account_id = a.id
GROUP BY a.id, a.balance
HAVING a.balance <> COALESCE(SUM(l.amount), 0)
ORDER BY a.id
LIMIT 5;

\echo '=== 2. Топ-10 пользователей по исходящему обороту USDT за 30 дней'
SELECT a.user_id, COUNT(*) AS transfers_cnt, SUM(t.amount) AS volume
FROM transfers t
JOIN accounts a ON a.id = t.from_account_id
WHERE t.status = 'completed' AND a.currency = 'USDT' AND t.created_at >= now() - interval '30 days'
GROUP BY a.user_id
ORDER BY volume DESC
LIMIT 10;

\echo '=== 3. Последние 3 проводки для каждого из аккаунтов 1..5 (LATERAL)'
SELECT a.id AS account_id, l.id, l.amount, l.created_at
FROM accounts a
CROSS JOIN LATERAL (
    SELECT id, amount, created_at FROM ledger_entries
    WHERE account_id = a.id ORDER BY id DESC LIMIT 3
) l
WHERE a.id BETWEEN 1 AND 5
ORDER BY a.id, l.id DESC;

\echo '=== 3b. То же через ROW_NUMBER()'
SELECT account_id, id, amount, created_at FROM (
    SELECT l.*, ROW_NUMBER() OVER (PARTITION BY account_id ORDER BY id DESC) AS rn
    FROM ledger_entries l WHERE account_id BETWEEN 1 AND 5
) x WHERE rn <= 3 ORDER BY account_id, id DESC;

\echo '=== 4. Бегущий баланс аккаунта 777'
SELECT id, created_at, amount,
       SUM(amount) OVER (ORDER BY created_at, id) AS running_balance
FROM ledger_entries
WHERE account_id = 777
ORDER BY created_at, id
LIMIT 10;

\echo '=== 5. Аккаунты без единой проводки за последние 90 дней (anti-join)'
SELECT COUNT(*) AS inactive_accounts
FROM accounts a
WHERE NOT EXISTS (
    SELECT 1 FROM ledger_entries l
    WHERE l.account_id = a.id AND l.created_at >= now() - interval '90 days'
);

\echo '=== 6. EXPLAIN ДО индекса'
EXPLAIN (ANALYZE, BUFFERS) SELECT * FROM ledger_entries WHERE account_id = 777 ORDER BY id DESC LIMIT 20;
CREATE INDEX ledger_entries_account_id_id_idx ON ledger_entries (account_id, id);
ANALYZE ledger_entries;
\echo '=== 6. EXPLAIN ПОСЛЕ индекса (account_id, id)'
EXPLAIN (ANALYZE, BUFFERS) SELECT * FROM ledger_entries WHERE account_id = 777 ORDER BY id DESC LIMIT 20;

\echo '=== 7. Частичный индекс под очередь pending-депозитов'
EXPLAIN (ANALYZE) SELECT id FROM deposits WHERE status = 'pending' ORDER BY created_at LIMIT 100;
CREATE INDEX deposits_pending_created_idx ON deposits (created_at) WHERE status = 'pending';
ANALYZE deposits;
EXPLAIN (ANALYZE) SELECT id FROM deposits WHERE status = 'pending' ORDER BY created_at LIMIT 100;

\echo '=== 8. Keyset-пагинация (вместо OFFSET), страница после id=500000'
SELECT id, amount, created_at FROM ledger_entries
WHERE account_id = 777 AND id < 500000
ORDER BY id DESC LIMIT 20;

\echo '=== 9. Оборот USDT по дням за 14 дней, дни без переводов = 0'
SELECT d::date AS day, COALESCE(SUM(t.amount), 0) AS volume, COUNT(t.id) AS cnt
FROM generate_series(current_date - 13, current_date, interval '1 day') d
LEFT JOIN (
    SELECT t.id, t.amount, t.created_at FROM transfers t
    JOIN accounts a ON a.id = t.from_account_id
    WHERE t.status = 'completed' AND a.currency = 'USDT'
) t ON t.created_at >= d AND t.created_at < d + interval '1 day'
GROUP BY d ORDER BY d;

\echo '=== 10. Идемпотентный upsert депозита: подтверждения только растут'
BEGIN;
INSERT INTO deposits (chain, txid, vout, account_id, amount, confirmations, status, created_at, updated_at)
VALUES ('btc', 'tx_demo_1', 0, 1, 1000, 2, 'pending', now(), now())
ON CONFLICT (chain, txid, vout) DO UPDATE
   SET confirmations = GREATEST(deposits.confirmations, EXCLUDED.confirmations), updated_at = now()
RETURNING id, confirmations, status;
INSERT INTO deposits (chain, txid, vout, account_id, amount, confirmations, status, created_at, updated_at)
VALUES ('btc', 'tx_demo_1', 0, 1, 1000, 1, 'pending', now(), now())
ON CONFLICT (chain, txid, vout) DO UPDATE
   SET confirmations = GREATEST(deposits.confirmations, EXCLUDED.confirmations), updated_at = now()
RETURNING id, confirmations, status;   -- остаётся 2, а не 1
ROLLBACK;

\echo '=== 11. Очередь воркеров: SKIP LOCKED (берём 5 pending депозитов, не мешая другим воркерам)'
BEGIN;
SELECT id, chain, txid FROM deposits WHERE status = 'pending' ORDER BY created_at LIMIT 5 FOR UPDATE SKIP LOCKED;
ROLLBACK;

\echo '=== Чистка: вернуть БД в "плохое" состояние для повторной тренировки'
DROP INDEX ledger_entries_account_id_id_idx;
DROP INDEX deposits_pending_created_idx;
