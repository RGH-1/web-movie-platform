import api from './api';

const endpoint = '/v1/admin/movies';

export async function getAdminMovies(filters, { signal } = {}) {
    const response = await api.get(endpoint, {
        params: { ...filters, search: filters.search || undefined, source: filters.source || undefined },
        signal,
    });
    return response.data;
}

export async function getAdminMovie(id, { signal } = {}) {
    const response = await api.get(`${endpoint}/${encodeURIComponent(id)}`, { signal });
    return response.data.data;
}

async function writeAdminMovie(method, url, data, signal) {
    await api.get(new URL('/sanctum/csrf-cookie', api.defaults.baseURL).href, { signal });
    const response = await api.request({ method, url, data, signal });
    return response.data.data;
}

export function saveAdminMovie(id, fields, { signal } = {}) {
    return id
        ? writeAdminMovie('patch', `${endpoint}/${encodeURIComponent(id)}`, fields, signal)
        : writeAdminMovie('post', endpoint, fields, signal);
}

export function deactivateAdminMovie(id, { signal } = {}) {
    return writeAdminMovie('delete', `${endpoint}/${encodeURIComponent(id)}`, undefined, signal);
}
