import test, { mock } from 'node:test';
import assert from 'node:assert/strict';
const requests = [];
let resolved = { tmdb_id: 157336, local_movie_id: '9007199254740993' };
mock.module('../src/services/api.js', { defaultExport: {
    defaults: { baseURL: 'http://localhost:8000/api' },
    async get(url, config) { requests.push(['get', url, config]); return { data: { data: { id: '7', local_movie_id: '9007199254740993', title: 'Local film' }, meta: { current_page: 2 } } }; },
    async post(url, fields, config) { requests.push(['post', url, fields, config]); return { data: { data: url.endsWith('/resolve') ? resolved : { movie_count: 1 } } }; },
    async delete(url, config) { requests.push(['delete', url, config]); },
} });
const { getList, addMovieToList, removeMovieFromList } = await import('../src/services/lists.js');
const { getLocalMovieDetails } = await import('../src/services/movies.js');

test('detail and removal requests keep canonical IDs as strings and forward cancellation', async () => {
    requests.length = 0;
    const signal = new AbortController().signal;
    await getList('9007199254740993', 2, { signal });
    assert.deepEqual(requests[0], ['get', '/v1/lists/9007199254740993', { params: { page: 2 }, signal }]);
    await removeMovieFromList('7', '9007199254740993', { signal });
    assert.equal(requests[1][1], 'http://localhost:8000/sanctum/csrf-cookie');
    assert.deepEqual(requests[2], ['delete', '/v1/lists/7/movies/9007199254740993', { signal }]);
});

test('adding a mapped movie uses its canonical ID without resolution', async () => {
    requests.length = 0;
    await addMovieToList('7', { local_movie_id: '9007199254740993', tmdb_id: 157336 });
    assert.equal(requests.length, 2);
    assert.equal(requests[1][1], '/v1/lists/7/movies');
    assert.deepEqual(requests[1][2], { movie_id: '9007199254740993' });
});

test('an explicit add resolves an unmapped TMDB movie before creating membership', async () => {
    requests.length = 0;
    await addMovieToList('7', { local_movie_id: null, tmdb_id: 157336 });
    assert.equal(requests.length, 3);
    assert.equal(requests[1][1], '/v1/movies/tmdb/157336/resolve');
    assert.deepEqual(requests[2][2], { movie_id: '9007199254740993' });
});

test('invalid resolution identity cannot create a membership', async () => {
    for (const value of [
        { tmdb_id: 42, local_movie_id: '7' },
        { tmdb_id: 157336, local_movie_id: 7 },
        { tmdb_id: 157336, local_movie_id: '01' },
        { tmdb_id: 157336, local_movie_id: '9223372036854775808' },
    ]) {
        requests.length = 0;
        resolved = value;
        await assert.rejects(addMovieToList('7', { tmdb_id: 157336 }));
        assert.equal(requests.filter(request => request[1] === '/v1/lists/7/movies').length, 0);
    }
});

test('inactive movies are rejected without API requests', async () => {
    requests.length = 0;
    await assert.rejects(addMovieToList('7', { local_movie_id: '42', catalog_deactivated: true }));
    assert.equal(requests.length, 0);
});

test('local details use the catalog endpoint without converting a bigint ID', async () => {
    requests.length = 0;
    const movie = await getLocalMovieDetails('9007199254740993');
    assert.equal(requests[0][1], '/v1/catalog/movies/9007199254740993');
    assert.equal(movie.title, 'Local film');
    assert.deepEqual(movie.genres, []);
    assert.deepEqual(movie.cast, []);
});
