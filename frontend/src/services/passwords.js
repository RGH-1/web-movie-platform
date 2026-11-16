import api from './api.js';

export async function changePassword(fields, { signal } = {}) {
    await api.get(new URL('/sanctum/csrf-cookie', api.defaults.baseURL).href, { signal });
    await api.post('/v1/auth/change-password', fields, { signal });
}

export async function requestPasswordReset(email, { signal } = {}) {
    await api.get(new URL('/sanctum/csrf-cookie', api.defaults.baseURL).href, { signal });
    await api.post('/v1/auth/forgot-password', { email }, { signal });
}

export async function resetPassword(fields, { signal } = {}) {
    await api.get(new URL('/sanctum/csrf-cookie', api.defaults.baseURL).href, { signal });
    await api.post('/v1/auth/reset-password', fields, { signal });
}
