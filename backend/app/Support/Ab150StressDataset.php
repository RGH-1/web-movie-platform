<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Deterministic, inspectable fixture set for the AB#150 MySQL integration run.
 *
 * This class deliberately uses the query builder: it is a test-data producer,
 * not application behaviour, and must not dispatch jobs or contact providers.
 */
final class Ab150StressDataset
{
    public const DATABASE = 'web_movie_platform_ab150_test';

    /** @return array<string, int> */
    public static function seed(): array
    {
        self::assertIsolatedMySql();
        self::assertNoApplicationData();

        $timestamp = Carbon::parse('2026-02-01 09:00:00');
        $users = self::insertUsers($timestamp);
        $movies = self::insertMovies($timestamp);
        $reviewPairs = [];
        $statusPairs = [];

        $addReview = static function (int $user, string $movie, int $rating, string $status) use (&$reviewPairs, $users, $movies, &$timestamp): void {
            $key = $user.':'.$movie;
            if (isset($reviewPairs[$key])) {
                throw new LogicException("Duplicate AB#150 review fixture for {$key}.");
            }
            $reviewPairs[$key] = true;
            DB::table('reviews')->insert([
                'user_id' => $users[$user],
                'movie_id' => $movies[$movie],
                'rating' => $rating,
                'review_text' => "AB150 deterministic review {$key}",
                'moderation_status' => $status,
                'sentiment_status' => 'skipped',
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
            $timestamp = $timestamp->copy()->addMinute();
        };
        $addStatus = static function (int $user, string $movie, ?Carbon $watchedAt, bool $watchlist) use (&$statusPairs, $users, $movies): void {
            $key = $user.':'.$movie;
            if (isset($statusPairs[$key])) {
                throw new LogicException("Duplicate AB#150 status fixture for {$key}.");
            }
            $statusPairs[$key] = true;
            DB::table('user_movie_statuses')->insert([
                'user_id' => $users[$user],
                'movie_id' => $movies[$movie],
                'in_watchlist' => $watchlist,
                'watched_at' => $watchedAt,
                'created_at' => $watchedAt ?? Carbon::parse('2026-02-02 09:00:00'),
                'updated_at' => $watchedAt ?? Carbon::parse('2026-02-02 09:00:00'),
            ]);
        };

        // Scenario A: the hidden rating must not contribute to the 4.00 / 2 baseline.
        $addReview(1, 'AB150 Rating Test', 5, 'visible');
        $addReview(2, 'AB150 Rating Test', 3, 'visible');
        $addReview(3, 'AB150 Rating Test', 1, 'hidden');

        // Scenario B and C fixtures.
        $addReview(1, 'AB150 Watched Test', 4, 'visible');
        $addReview(2, 'AB150 Watched Test', 4, 'visible');
        $addReview(1, 'AB150 Genre Dedup Test', 5, 'visible');
        $addReview(2, 'AB150 Genre Dedup Test', 4, 'visible');

        // Scenario D: one higher review-count tie and two complete ties.
        foreach ([1, 2, 3] as $user) {
            $addReview($user, 'AB150 Tie Count Three', 4, 'visible');
        }
        foreach ([4, 5] as $user) {
            $addReview($user, 'AB150 Tie Canonical One', 4, 'visible');
            $addReview($user + 2, 'AB150 Tie Canonical Two', 4, 'visible');
        }

        $genericMovies = array_values(array_filter(array_keys($movies), static fn (string $title): bool => ! str_starts_with($title, 'AB150 Rating')
            && ! str_starts_with($title, 'AB150 Watched')
            && ! str_starts_with($title, 'AB150 Genre')
            && ! str_starts_with($title, 'AB150 Tie')));

        $reviewUser = 10;
        $reviewMovie = 0;
        while (count($reviewPairs) < 165) {
            $title = $genericMovies[$reviewMovie % count($genericMovies)];
            $key = $reviewUser.':'.$title;
            if (! isset($reviewPairs[$key])) {
                $addReview($reviewUser, $title, 2, $reviewMovie % 5 === 0 ? 'hidden' : 'visible');
            }
            $reviewUser = $reviewUser === 120 ? 10 : $reviewUser + 1;
            $reviewMovie++;
        }

        $watchBase = Carbon::parse('2026-03-01 10:00:00');
        $addStatus(1, 'AB150 Watched Test', $watchBase, false);
        $addStatus(2, 'AB150 Watched Test', $watchBase->copy()->addMinute(), false);
        $addStatus(3, 'AB150 Watched Test', null, true);
        $addStatus(1, 'AB150 Genre Dedup Test', $watchBase->copy()->addMinutes(2), false);
        $addStatus(3, 'AB150 Genre Dedup Test', $watchBase->copy()->addMinutes(3), false);

        // Keep generic watch counts at one or two so the named two-watch case is in the top ten.
        $statusMovies = array_values(array_filter($genericMovies, static fn (string $title): bool => $title !== 'AB150 Empty Genres'));
        for ($index = 0; $index < 85; $index++) {
            $title = $statusMovies[intdiv($index, 2) % count($statusMovies)];
            $user = 20 + ($index % 80);
            while (isset($statusPairs[$user.':'.$title])) {
                $user = $user === 120 ? 20 : $user + 1;
            }
            $watchedAt = $watchBase->copy()->addMinutes($index >= 83 ? 88 : $index + 4);
            $addStatus($user, $title, $watchedAt, false);
        }
        for ($index = 0; $index < 140; $index++) {
            $title = $statusMovies[$index % count($statusMovies)];
            $user = 100 - ($index % 80);
            while (isset($statusPairs[$user.':'.$title])) {
                $user = $user === 1 ? 120 : $user - 1;
            }
            $addStatus($user, $title, null, true);
        }

        return self::counts();
    }

    public static function assertIsolatedMySql(): void
    {
        $connection = DB::connection();
        $actual = $connection->getDatabaseName();
        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true) || $actual !== self::DATABASE) {
            throw new LogicException(sprintf(
                'AB#150 safety stop: active connection is %s/%s; expected mysql or mariadb/%s.',
                $connection->getDriverName(),
                $actual,
                self::DATABASE,
            ));
        }
    }

    public static function assertNoApplicationData(): void
    {
        foreach (['users', 'movies', 'reviews', 'user_movie_statuses'] as $table) {
            if (DB::getSchemaBuilder()->hasTable($table) && DB::table($table)->exists()) {
                throw new LogicException("AB#150 safety stop: {$table} already contains data; nothing was deleted.");
            }
        }
    }

    /** @return array<string, int> */
    public static function counts(): array
    {
        return [
            'users' => DB::table('users')->count(),
            'movies' => DB::table('movies')->count(),
            'reviews' => DB::table('reviews')->count(),
            'visible_reviews' => DB::table('reviews')->where('moderation_status', 'visible')->count(),
            'statuses' => DB::table('user_movie_statuses')->count(),
            'watched_entries' => DB::table('user_movie_statuses')->whereNotNull('watched_at')->count(),
            'watchlist_entries' => DB::table('user_movie_statuses')->where('in_watchlist', true)->count(),
        ];
    }

    /** @return array<int, int> */
    private static function insertUsers(Carbon $timestamp): array
    {
        $ids = [];
        for ($index = 0; $index <= 120; $index++) {
            $id = DB::table('users')->insertGetId([
                'name' => $index === 0 ? 'AB150 Administrator' : sprintf('AB150 User %03d', $index),
                'email' => $index === 0 ? 'ab150-admin@example.test' : sprintf('ab150-user-%03d@example.test', $index),
                'email_verified_at' => $timestamp,
                'password' => bcrypt('ab150-fixture-password'),
                'is_admin' => $index === 0,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
            $ids[$index] = $id;
        }

        return $ids;
    }

    /** @return array<string, int> */
    private static function insertMovies(Carbon $timestamp): array
    {
        $titles = [
            'AB150 Rating Test', 'AB150 Watched Test', 'AB150 Genre Dedup Test',
            'AB150 Tie Count Three', 'AB150 Tie Canonical One', 'AB150 Tie Canonical Two',
        ];
        for ($index = 1; $index <= 39; $index++) {
            $titles[] = $index === 39 ? 'AB150 Empty Genres' : sprintf('AB150 Catalog %02d', $index);
        }

        $ids = [];
        foreach ($titles as $index => $title) {
            $genres = match ($title) {
                'AB150 Genre Dedup Test' => [
                    ['tmdb_id' => 18, 'name' => 'Drama'],
                    ['tmdb_id' => 878, 'name' => 'Science Fiction'],
                ],
                'AB150 Empty Genres' => [],
                default => self::genresFor($index),
            };
            if ($title === 'AB150 Catalog 38') {
                $genres = [['tmdb_id' => 28, 'name' => 'Action'], ['tmdb_id' => 28, 'name' => 'Action'], ['name' => 'Local Genre']];
            }
            if ($title === 'AB150 Catalog 37') {
                $genres = [['tmdb_id' => 99], 'not-an-object', ['name' => '  Local Genre  ']];
            }
            $ids[$title] = DB::table('movies')->insertGetId([
                'source' => 'local',
                'tmdb_id' => null,
                'title' => $title,
                'genres' => json_encode($genres, JSON_THROW_ON_ERROR),
                'cast' => '[]',
                'metadata_overrides' => '[]',
                'poster_url' => "https://example.test/{$index}.jpg",
                'deactivated_at' => in_array($title, ['AB150 Catalog 35', 'AB150 Catalog 36'], true) ? $timestamp->copy()->addDay() : null,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        }

        return $ids;
    }

    /** @return list<array{tmdb_id: int, name: string}> */
    private static function genresFor(int $index): array
    {
        $genres = [
            ['tmdb_id' => 18, 'name' => 'Drama'],
            ['tmdb_id' => 28, 'name' => 'Action'],
            ['tmdb_id' => 35, 'name' => 'Comedy'],
            ['tmdb_id' => 12, 'name' => 'Adventure'],
            ['tmdb_id' => 878, 'name' => 'Science Fiction'],
            ['tmdb_id' => 53, 'name' => 'Thriller'],
        ];

        return [$genres[$index % count($genres)], $genres[($index + 1) % count($genres)]];
    }
}
