import api from './api.js';

export async function searchMovies(query, page = 1) {
    const response = await api.get('/v1/movies/search', {
        params: {
            query,
            page,
        },
    });

    return response.data;
}



export async function getMovieDetails(movieId) {
    const id = String(movieId);

    const isValidId =
        /^\d+$/.test(id) &&
        Number.isSafeInteger(Number(id)) &&
        Number(id) >= 1 &&
        Number(id) <= 2147483647;

    if (!isValidId) {
        throw new Error('Invalid movie ID.');
    }

    const response = await api.get(`/v1/movies/${Number(id)}`);

    return response.data.data;
}