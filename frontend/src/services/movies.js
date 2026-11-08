import api from './api';

export async function searchMovies(query, page = 1) {
    const response = await api.get('/v1/movies/search', {
        params: {
            query,
            page,
        },
    });

    return response.data;
}
