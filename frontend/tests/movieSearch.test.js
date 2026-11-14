import test from 'node:test';
import assert from 'node:assert/strict';
import { mock } from 'node:test';

const requests = [];

mock.module('../src/services/api.js', {
    defaultExport: {
        async get(url, config) {
            requests.push({ url, config });

            return {
                data: {
                    data: [
                        {
                            tmdb_id: 597,
                            title: 'Titanic',
                        },
                    ],
                    meta: {
                        page: 1,
                        total_pages: 2,
                        total_results: 25,
                    },
                },
            };
        },
    },
});

const { searchMovies, discoverMovies, getMovieGenres } = await import('../src/services/movies.js');

test('search sends query and default page to Laravel', async () => {
    requests.length = 0;

    const result = await searchMovies('Titanic');

    assert.equal(requests.length, 1);
    assert.equal(requests[0].url, '/v1/movies/search');
    assert.deepEqual(requests[0].config.params, {
        query: 'Titanic',
        page: 1,
    });

    assert.equal(result.data[0].title, 'Titanic');
});

test('search sends requested pagination page', async () => {
    requests.length = 0;

    await searchMovies('Avatar', 3);

    assert.equal(requests.length, 1);
    assert.deepEqual(requests[0].config.params, {
        query: 'Avatar',
        page: 3,
    });
});

test('search returns Laravel response metadata', async () => {
    const result = await searchMovies('Titanic');

    assert.deepEqual(result.meta, {
        page: 1,
        total_pages: 2,
        total_results: 25,
    });
});

test('discovery forwards combined filters and page but omits empty and unknown parameters', async () => {
    requests.length = 0;
    const result = await discoverMovies({ genre_id: 28, year: 2024, min_rating: 0, sort_by: 'rating_desc', with_genres: 99 }, 2);
    assert.equal(requests[0].url, '/v1/movies/discover');
    assert.deepEqual(requests[0].config.params, { genre_id: 28, year: 2024, min_rating: 0, sort_by: 'rating_desc', page: 2 });
    assert.equal(result.meta.page, 1);
    requests.length = 0;
    await discoverMovies({ genre_id: '', year: '', min_rating: '' });
    assert.deepEqual(requests[0].config.params, { page: 1 });
});

test('genre selector service uses the Laravel genre endpoint', async () => {
    requests.length = 0;
    const genres = await getMovieGenres();
    assert.equal(requests[0].url, '/v1/movies/genres');
    assert.ok(Array.isArray(genres));
});
