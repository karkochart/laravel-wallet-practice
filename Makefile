# Все команды выполняются в контейнерах. Запуск: make <цель>
EXEC = docker compose exec php

up:            ## поднять php + postgres
	docker compose up -d --build
down:
	docker compose down
test:          ## все тесты (PostgreSQL, база wallet_test)
	$(EXEC) php artisan test
t:             ## один тест: make t F=test_name
	$(EXEC) php artisan test --filter=$(F)
psql:          ## консоль PostgreSQL
	docker compose exec db psql -U wallet -d wallet
seed:          ## большой датасет для SQL-тренировки (~2 мин)
	$(EXEC) php artisan practice:seed-big --force
race:          ## гонка: правильный TransferService (нужна решённая задача 1)
	$(EXEC) php artisan practice:race
race-naive:    ## гонка: сломанный сервис - посмотреть, как теряются деньги
	$(EXEC) php artisan practice:race --naive
octane:        ## RoadRunner на :8011 (долгоживущие воркеры)
	$(EXEC) php artisan octane:start --server=roadrunner --host=0.0.0.0 --port=8001 --workers=2
sh:
	$(EXEC) bash
