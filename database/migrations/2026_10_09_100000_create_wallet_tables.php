<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Деньги: NUMERIC(78,0) в минимальных единицах (satoshi / wei / 1e-6 USDT).
 * 78 цифр вмещают uint256. Никакого float.
 *
 * ВАЖНО: индексов на ledger_entries(account_id) и (created_at) тут НЕТ намеренно -
 * это материал для SQL-тренировки (sql/drills.md, задание про EXPLAIN).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('currency', 16);                       // BTC | ETH | USDT
            $t->decimal('balance', 78, 0)->default(0);        // проекция суммы проводок
            $t->string('deposit_address', 128)->nullable()->unique();
            $t->timestamps();
            $t->unique(['user_id', 'currency']);
        });
        DB::statement('ALTER TABLE accounts ADD CONSTRAINT accounts_balance_non_negative CHECK (balance >= 0)');

        Schema::create('transfers', function (Blueprint $t) {
            $t->id();
            $t->string('idempotency_key', 64)->unique();
            $t->char('request_hash', 64);                     // sha256 параметров: тот же ключ + другие данные = конфликт
            $t->foreignId('from_account_id')->constrained('accounts');
            $t->foreignId('to_account_id')->constrained('accounts');
            $t->decimal('amount', 78, 0);
            $t->string('status', 16)->default('pending');     // pending | completed | failed
            $t->timestamps();
        });
        DB::statement('ALTER TABLE transfers ADD CONSTRAINT transfers_amount_positive CHECK (amount > 0)');

        Schema::create('deposits', function (Blueprint $t) {
            $t->id();
            $t->string('chain', 16);                          // btc | eth | tron
            $t->string('txid', 128);
            $t->unsignedInteger('vout')->default(0);          // BTC: номер выхода; ETH/TRON: log_index или 0
            $t->foreignId('account_id')->constrained('accounts');
            $t->decimal('amount', 78, 0);
            $t->unsignedInteger('confirmations')->default(0);
            $t->string('status', 16)->default('pending');     // pending | credited
            $t->timestamps();
            $t->unique(['chain', 'txid', 'vout']);            // защита от двойной обработки на уровне БД
        });

        Schema::create('ledger_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('transfer_id')->nullable()->constrained('transfers');
            $t->foreignId('deposit_id')->nullable()->constrained('deposits');
            $t->foreignId('account_id')->constrained('accounts');
            $t->decimal('amount', 78, 0);                     // со знаком: дебет < 0, кредит > 0
            $t->timestampTz('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('deposits');
        Schema::dropIfExists('transfers');
        Schema::dropIfExists('accounts');
    }
};
