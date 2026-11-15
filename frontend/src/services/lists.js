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

export async function getList(id, page = 1, { signal } = {}) {
    const response = await api.get(`/v1/lists/${id}`, { params: { page }, signal });
    return response.data;
}

export async function addMovieToList(id, movie, { signal } = {}) {
    if (movie.catalog_deactivated || movie.is_active === false) throw new Error('Movie is inactive.');
    if (movie.local_movie_id == null && (!Number.isInteger(movie.tmdb_id) || movie.tmdb_id < 1 || movie.tmdb_id > 2147483647)) {
        throw new Error('Invalid TMDB movie ID.');
    }
    await api.get(new URL('/sanctum/csrf-cookie', api.defaults.baseURL).href, { signal });
    let movieId = movie.local_movie_id;
    if (movieId == null) {
        const resolved = await api.post(`/v1/movies/tmdb/${movie.tmdb_id}/resolve`, null, { signal });
        if (resolved.data?.data?.tmdb_id !== movie.tmdb_id) throw new Error('Invalid movie resolution response.');
        movieId = resolved.data.data.local_movie_id;
    }
    if (typeof movieId !== 'string' || !/^[1-9][0-9]{0,18}$/.test(movieId) ||
        (movieId.length === 19 && movieId > '9223372036854775807')) throw new Error('Invalid canonical movie ID.');
    const response = await api.post(`/v1/lists/${id}/movies`, { movie_id: movieId }, { signal });
    return response.data.data;
}

export async function removeMovieFromList(id, movieId, { signal } = {}) {
    await api.get(new URL('/sanctum/csrf-cookie', api.defaults.baseURL).href, { signal });
    await api.delete(`/v1/lists/${id}/movies/${movieId}`, { signal });
}
