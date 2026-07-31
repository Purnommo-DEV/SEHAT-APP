<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use Illuminate\Cache\CacheManager;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ReadinessController extends Controller
{
    public function __invoke(DatabaseManager $database, CacheManager $cache): JsonResponse
    {
        $checks = [
            'database' => fn (): mixed => $database->select('SELECT 1'),
            'cache' => function () use ($cache): bool {
                $key = 'health:readiness:'.bin2hex(random_bytes(8));
                $cache->put($key, true, 10);
                $healthy = $cache->get($key) === true;
                $cache->forget($key);

                return $healthy;
            },
        ];
        $results = [];

        foreach ($checks as $name => $check) {
            try {
                $results[$name] = $check() !== false;
            } catch (Throwable $exception) {
                report($exception);
                $results[$name] = false;
            }
        }

        $healthy = ! in_array(false, $results, true);

        return response()->json([
            'status' => $healthy ? 'ready' : 'unavailable',
            'checks' => $results,
            'checked_at' => now()->toIso8601String(),
        ], $healthy ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE);
    }
}
