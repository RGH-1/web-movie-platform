import api from './api';

const endpoint = '/v1/movie-nights';

export async function getMovieNights(page, { signal } = {}) {
    const response = await api.get(endpoint, { params: { page }, signal });
    return response.data;
}

export async function getMovieNight(id, { signal } = {}) {
    const response = await api.get(`${endpoint}/${encodeURIComponent(id)}`, { signal });
    return response.data.data;
}

async function writeMovieNight(method, url, data, signal) {
    await api.get(new URL('/sanctum/csrf-cookie', api.defaults.baseURL).href, { signal });
    const response = await api.request({ method, url, data, signal });
    return response.data.data;
}

export function saveMovieNight(id, fields, { signal } = {}) {
    return id
        ? writeMovieNight('patch', `${endpoint}/${encodeURIComponent(id)}`, fields, signal)
        : writeMovieNight('post', endpoint, fields, signal);
}

export function cancelMovieNight(id, { signal } = {}) {
    return writeMovieNight('post', `${endpoint}/${encodeURIComponent(id)}/cancel`, undefined, signal);
}
