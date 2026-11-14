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

    const movie = response.data?.data;
    if (!movie || movie.tmdb_id !== Number(id) ||
        typeof movie.title !== 'string' || !movie.title.trim()) {
        throw new Error('Invalid movie details response.');
    }

    return {
        ...movie,
        genres: Array.isArray(movie.genres) ? movie.genres : [],
        cast: Array.isArray(movie.cast) ? movie.cast : [],
    };
}
export async function discoverMovies(filters = {}, page = 1) {
    const params = Object.fromEntries(
        Object.entries(filters).filter(([key, value]) =>
            ['genre_id', 'year', 'min_rating', 'sort_by'].includes(key) && value !== '' && value != null
        )
    );
    const response = await api.get('/v1/movies/discover', { params: { ...params, page } });
    return response.data;
}

export async function getMovieGenres() {
    const response = await api.get('/v1/movies/genres');
    return response.data.data;
}
