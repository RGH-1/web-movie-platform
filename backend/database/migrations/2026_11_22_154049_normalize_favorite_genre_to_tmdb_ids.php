<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Keep this historical conversion independent of future application changes.
    private const IDS_BY_SLUG = [
        'action' => 28,
        'adventure' => 12,
        'animation' => 16,
        'comedy' => 35,
        'crime' => 80,
        'documentary' => 99,
        'drama' => 18,
        'family' => 10751,
        'fantasy' => 14,
        'history' => 36,
        'horror' => 27,
        'music' => 10402,
        'mystery' => 9648,
        'romance' => 10749,
        'science-fiction' => 878,
        'tv-movie' => 10770,
        'thriller' => 53,
        'war' => 10752,
        'western' => 37,
    ];

    public function up(): void
    {
        $this->convert(toIds: true);
    }

    public function down(): void
    {
        $this->convert(toIds: false);
    }

    private function convert(bool $toIds): void
    {
        DB::transaction(function () use ($toIds): void {
            DB::table('users')
                ->select(['id', 'favorite_genres'])
                ->whereNotNull('favorite_genres')
                ->chunkById(200, function ($users) use ($toIds): void {
                    foreach ($users as $user) {
                        try {
                            $values = json_decode(
                                $user->favorite_genres,
                                true,
                                512,
                                JSON_THROW_ON_ERROR,
                            );
                        } catch (\JsonException $exception) {
                            throw new \RuntimeException(
                                "Invalid favorite genre JSON for user {$user->id}.",
                                previous: $exception,
                            );
                        }

                        if (! is_array($values)
                            || ! array_is_list($values)
                            || count($values) > 5) {
                            throw new \RuntimeException(
                                "Invalid favorite genre list for user {$user->id}.",
                            );
                        }

                        $ids = [];

                        foreach ($values as $value) {
                            $id = is_int($value)
                                ? $value
                                : (is_string($value)
                                    ? (self::IDS_BY_SLUG[$value] ?? null)
                                    : null);

                            if ($id === null
                                || ! in_array($id, self::IDS_BY_SLUG, true)
                                || in_array($id, $ids, true)) {
                                throw new \RuntimeException(
                                    "Unsupported or duplicate favorite genre for user {$user->id}.",
                                );
                            }

                            $ids[] = $id;
                        }

                        $converted = $toIds
                            ? $ids
                            : array_map(
                                fn (int $id): string => array_search(
                                    $id,
                                    self::IDS_BY_SLUG,
                                    true,
                                ),
                                $ids,
                            );

                        // Preserve profile timestamps and avoid application observers.
                        DB::table('users')
                            ->where('id', $user->id)
                            ->update([
                                'favorite_genres' => json_encode(
                                    $converted,
                                    JSON_THROW_ON_ERROR,
                                ),
                            ]);
                    }
                });
        });
    }
};