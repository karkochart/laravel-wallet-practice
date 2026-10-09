# Laravel Wallet - тренажёр перед собеседованием

Мини-ядро кошелька (аккаунты, переводы, проводки, депозиты по txid) на Laravel 13 / PHP 8.4 / PostgreSQL 16. Специально неполное: часть кода заменена заглушками, тесты красные - это задания.

* **План на 2 дня:** [PLAN.md](PLAN.md)
* **Задания по коду:** задача 1 `tests/Feature/TransferServiceTest.php`, задача 2 `tests/Feature/DepositWebhookTest.php`, задача 3 `tests/Unit/ChainTest.php` (условия - в docblock у каждого теста)
* **SQL:** [sql/drills.md](sql/drills.md) (задания), [sql/solutions.sql](sql/solutions.sql) (решения), [sql/interview-questions.md](sql/interview-questions.md) (теория с ответами)
* **Эталонные решения** в `solutions/` (проверены: 34/34 тестов зелёные) - смотреть после попытки.

```
cp .env.example .env
make up      # php 8.4 + postgres, composer install + migrate
make test    # phpunit на PostgreSQL (база wallet_test)
make seed    # большой датасет для SQL-заданий
```
