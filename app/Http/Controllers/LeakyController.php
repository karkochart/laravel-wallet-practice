<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * УЧЕБНЫЙ БАГ про долгоживущие воркеры (RoadRunner / Swoole / FrankenPHP).
 *
 *   make serve   -> php artisan serve: на каждый запрос процесс "чистый", баг не виден
 *   make octane  -> воркер живёт между запросами: static переживает запрос
 *
 * Опыт: curl localhost:8010/leak/alice ; curl localhost:8010/leak/bob     (баг не виден)
 *       curl localhost:8011/leak/alice ; curl localhost:8011/leak/bob     (bob видит alice)
 *
 * Задание: починить так, чтобы состояние запроса не переживало запрос
 * (подсказки: не хранить request-scoped данные в static/синглтонах; scoped() вместо singleton() в контейнере;
 * Octane сбрасывает контейнер между запросами, но не ваши static-свойства).
 */
class LeakyController extends Controller
{
    private static array $seen = [];
    private static int $hits = 0;

    public function __invoke(Request $request, string $name)
    {
        self::$seen[] = $name;
        self::$hits++;

        return response()->json([
            'you' => $name,
            'worker_pid' => getmypid(),
            'hits_in_this_process' => self::$hits,
            'others_seen_in_this_process' => self::$seen,   // чужие данные!
        ]);
    }
}
