# SQL-тренировка (PostgreSQL, ~1,1 млн проводок)

Подготовка (один раз, ~2 минуты): `make seed`. Консоль: `make psql`.
Схема: `users → accounts (user_id, currency, balance) → ledger_entries (account_id, amount со знаком)`, `transfers`, `deposits (chain, txid, vout)`.
Решения: `sql/solutions.sql` (всё прогнано на этих данных). Сначала решайте сами, засекайте время: на реальном интервью на запрос дают 5-10 минут.

| # | Задание | Что проверяют |
|---|---------|---------------|
| 1 | **Сверка.** Найти аккаунты, у которых `accounts.balance` ≠ сумме их проводок. Вывести разницу. (Намеренно испорчено 25 аккаунтов.) | JOIN + GROUP BY + HAVING, LEFT JOIN и NULL (`COALESCE`) |
| 2 | **Топ-10 пользователей** по сумме исходящих переводов USDT за 30 дней (только `completed`). | JOIN, фильтры, агрегаты, `ORDER BY ... LIMIT` |
| 3 | **Последние 3 проводки** для каждого из аккаунтов 1..5. Двумя способами. | `ROW_NUMBER() OVER (PARTITION BY ...)` и `LATERAL` |
| 4 | **Бегущий баланс** аккаунта 777 по времени. | оконная `SUM() OVER (ORDER BY ...)`, детерминированная сортировка (`created_at, id`) |
| 5 | **Аккаунты без операций за 90 дней.** Почему `NOT EXISTS`, а не `NOT IN` и не `LEFT JOIN ... IS NULL`? | anti-join, ловушка `NOT IN` с NULL |
| 6 | **Медленный запрос.** `SELECT * FROM ledger_entries WHERE account_id = 777 ORDER BY id DESC LIMIT 20`. Снять `EXPLAIN (ANALYZE, BUFFERS)`, объяснить план, добавить индекс, сравнить. Почему порядок колонок `(account_id, id)`, а не `(id, account_id)`? | чтение плана, составной индекс, Seq Scan → Index Scan |
| 7 | **Очередь pending-депозитов.** `WHERE status='pending' ORDER BY created_at LIMIT 100`: 85 тыс. pending из 300 тыс. Какой индекс? | частичный индекс (`WHERE status='pending'`) |
| 8 | **Пагинация** истории аккаунта: чем плох `OFFSET 100000` и как переписать на keyset. | keyset по `(id)`, стабильная сортировка |
| 9 | **Оборот USDT по дням** за 14 дней, дни без переводов - с нулём. | `generate_series` + LEFT JOIN |
| 10 | **Идемпотентный upsert** депозита: повторный вебхук с меньшим `confirmations` не должен уменьшать значение. | `INSERT ... ON CONFLICT DO UPDATE`, `GREATEST`, `EXCLUDED` |
| 11 | **Очередь воркеров:** взять 5 pending-депозитов так, чтобы два воркера не взяли одни и те же. | `FOR UPDATE SKIP LOCKED` |

## Практика блокировок (руками, два терминала)

Откройте две консоли `make psql`.

**A. Потерянное обновление.** В обеих: `BEGIN; SELECT balance FROM accounts WHERE id=1;` → в обеих `UPDATE accounts SET balance = <прочитанное> - 100 WHERE id=1; COMMIT;`.
Что случилось с балансом? Теперь повторите с `SELECT ... FOR UPDATE` - что делает вторая сессия и почему?

**B. Deadlock.** Сессия 1: `BEGIN; UPDATE accounts SET balance=balance WHERE id=1;`. Сессия 2: `BEGIN; UPDATE accounts SET balance=balance WHERE id=2;`.
Затем сессия 1: `UPDATE ... WHERE id=2;` (зависнет), сессия 2: `UPDATE ... WHERE id=1;` → `ERROR: deadlock detected`. Как это лечится в коде перевода? (порядок блокировок по id)

**C. Уровни изоляции.** Сессия 1: `BEGIN ISOLATION LEVEL REPEATABLE READ; SELECT count(*) FROM deposits;`. Сессия 2: вставить депозит, COMMIT. Сессия 1 снова `SELECT count(*)` - увидит ли? Повторите с `READ COMMITTED` (дефолт).

**D. Что видно в `pg_stat_activity`:** `SELECT pid, state, wait_event_type, query FROM pg_stat_activity WHERE datname='wallet';` - найдите заблокированную сессию и того, кто блокирует (`pg_blocking_pids(pid)`).
