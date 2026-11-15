import api from './api.js';

export async function getMyLists(page = 1, { signal } = {}) {
    const response = await api.get('/v1/me/lists', { params: { page }, signal });
    return response.data;
}

export async function createList(fields, { signal } = {}) {
    await api.get(new URL('/sanctum/csrf-cookie', api.defaults.baseURL).href, { signal });
    const response = await api.post('/v1/lists', fields, { signal });
    return response.data.data;
}

export async function updateList(id, fields, { signal } = {}) {
    await api.get(new URL('/sanctum/csrf-cookie', api.defaults.baseURL).href, { signal });
    const response = await api.patch(`/v1/lists/${id}`, fields, { signal });
    return response.data.data;
}

export async function deleteList(id, { signal } = {}) {
    await api.get(new URL('/sanctum/csrf-cookie', api.defaults.baseURL).href, { signal });
    await api.delete(`/v1/lists/${id}`, { signal });
}
