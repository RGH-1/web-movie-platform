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

const { searchMovies } = await import('../src/services/movies.js');

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
