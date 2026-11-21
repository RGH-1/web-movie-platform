<?php

namespace App\Services;

use App\Exceptions\TmdbRequestException;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** Shared TMDB cache with single-flight misses and a short failure cooldown. */
class RecommendationProviderCache
{
    public function __construct(private ProviderAttemptBudget $budget) {}

    /**
     * @param  callable():array<string,mixed>  $fetch
     * @return array{state:'cached'|'fetched'|'budget_exhausted'|'unavailable',data:array<string,mixed>|null}
     */
    public function remember(string $key, int $seconds, callable $fetch): array
    {
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return ['state' => 'cached', 'data' => $cached];
        }
        if (Cache::get($key.':failure') === true) {
            return ['state' => 'unavailable', 'data' => null];
        }

        try {
            $lock = Cache::lock($key.':lock', 5);
            if (! $lock->get()) {
                // A request already owns the miss.  Do not make a duplicate
                // outbound call; return whatever became available meanwhile.
                $cached = Cache::get($key);

                return is_array($cached)
                    ? ['state' => 'cached', 'data' => $cached]
                    : ['state' => 'unavailable', 'data' => null];
            }
        } catch (Throwable) {
            // A non-shared/non-locking cache driver cannot safely coalesce
            // misses, so it must fail closed rather than duplicate requests.
            return ['state' => 'unavailable', 'data' => null];
        }

        try {
            $cached = Cache::get($key);
            if (is_array($cached)) {
                return ['state' => 'cached', 'data' => $cached];
            }
            if (! $this->budget->allowTmdb()) {
                return ['state' => 'budget_exhausted', 'data' => null];
            }
            $data = $fetch();
            Cache::put($key, $data, $seconds);

            return ['state' => 'fetched', 'data' => $data];
        } catch (Throwable $exception) {
            $cooldown = $exception instanceof TmdbRequestException && is_numeric($exception->retryAfter)
                ? max(60, (int) $exception->retryAfter)
                : 60;
            Cache::put($key.':failure', true, $cooldown);

            return ['state' => 'unavailable', 'data' => null];
        } finally {
            $lock->release();
        }
    }
}
