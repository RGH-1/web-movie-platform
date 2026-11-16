import test, { mock } from 'node:test';
import assert from 'node:assert/strict';
const requests = [];
mock.module('../src/services/api.js', { defaultExport: {
    defaults: { baseURL: 'http://localhost:8000/api' },
    async get(url, config) { requests.push(['get', url, config]); },
    async post(url, fields, config) { requests.push(['post', url, fields, config]); },
} });
const { changePassword, requestPasswordReset, resetPassword } = await import('../src/services/passwords.js');

test('all password writes initialize CSRF and preserve exact values/cancellation', async () => {
    const signal = new AbortController().signal;
    const password = ' SecureNew!123 ';
    for (const [path, run, body] of [
        ['change-password', () => changePassword({ current_password: ' Old!12345678 ', password, password_confirmation: password }, { signal }), { current_password: ' Old!12345678 ', password, password_confirmation: password }],
        ['forgot-password', () => requestPasswordReset('sam@example.com', { signal }), { email: 'sam@example.com' }],
        ['reset-password', () => resetPassword({ email: 'sam@example.com', token: 'dummy-token', password, password_confirmation: password }, { signal }), { email: 'sam@example.com', token: 'dummy-token', password, password_confirmation: password }],
    ]) {
        requests.length = 0;
        await run();
        assert.deepEqual(requests, [['get', 'http://localhost:8000/sanctum/csrf-cookie', { signal }], ['post', `/v1/auth/${path}`, body, { signal }]]);
    }
});
