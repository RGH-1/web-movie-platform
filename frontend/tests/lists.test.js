import test, { mock } from 'node:test';
import assert from 'node:assert/strict';
const requests = [];
mock.module('../src/services/api.js', { defaultExport: {
    defaults: { baseURL: 'http://localhost:8000/api' },
    async get(url, config) { requests.push(['get', url, config]); return { data: { data: [], meta: { current_page: 2 } } }; },
    async post(url, fields, config) { requests.push(['post', url, fields, config]); return { data: { data: fields } }; },
    async patch(url, fields, config) { requests.push(['patch', url, fields, config]); return { data: { data: fields } }; },
    async delete(url, config) { requests.push(['delete', url, config]); },
} });
const { getMyLists, createList, updateList, deleteList } = await import('../src/services/lists.js');

test('owned list reads preserve page and cancellation signal', async () => {
    requests.length = 0;
    const signal = new AbortController().signal;
    const result = await getMyLists(2, { signal });
    assert.deepEqual(requests, [['get', '/v1/me/lists', { params: { page: 2 }, signal }]]);
    assert.equal(result.meta.current_page, 2);
});

test('writes refresh CSRF and preserve string IDs and visibility', async () => {
    for (const [method, write, url] of [
        ['post', (fields) => createList(fields), '/v1/lists'],
        ['patch', (fields) => updateList('9007199254740993', fields), '/v1/lists/9007199254740993'],
        ['delete', () => deleteList('9007199254740993'), '/v1/lists/9007199254740993'],
    ]) {
        requests.length = 0;
        await write({ name: 'Weekend', description: null, is_public: false });
        assert.equal(requests[0][1], 'http://localhost:8000/sanctum/csrf-cookie');
        assert.equal(requests[1][0], method);
        assert.equal(requests[1][1], url);
        if (method !== 'delete') assert.equal(requests[1][2].is_public, false);
    }
});
