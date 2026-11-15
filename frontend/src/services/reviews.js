import api from './api.js';

export async function saveMovieReview({
    movieId,
    reviewId,
    rating,
    reviewText,
    signal,
}) {
    const payload = { rating, review_text: reviewText };
    const response = reviewId === null
        ? await api.post(
            `/v1/catalog/movies/${encodeURIComponent(movieId)}/reviews`,
            payload,
            { signal },
        )
        : await api.patch(
            `/v1/reviews/${encodeURIComponent(reviewId)}`,
            payload,
            { signal },
        );

    return response.data.data;
}


export async function prepareReviewWrite(signal) {
    const csrfUrl = new URL('/sanctum/csrf-cookie', api.defaults.baseURL).href;
    await api.get(csrfUrl, { signal });
}


export async function resolveMovieForReview(tmdbId, signal) {
    const response = await api.post(
        `/v1/movies/tmdb/${encodeURIComponent(tmdbId)}/resolve`,
        null,
        { signal },
    );

    const data = response.data?.data;
    if (
        data?.tmdb_id !== tmdbId ||
        typeof data.local_movie_id !== 'string' ||
        !/^[1-9]\d*$/.test(data.local_movie_id)
    ) {
        throw new Error('Invalid movie resolution response.');
    }

    return data.local_movie_id;
}


export async function getMovieReviews(movieId, page, { signal } = {}) {
    const response = await api.get(
        `/v1/catalog/movies/${encodeURIComponent(movieId)}/reviews`,
        { params: { page }, signal },
    );
    const { data, meta } = response.data ?? {};

    if (
        !Array.isArray(data) ||
        !Number.isInteger(meta?.current_page) ||
        !Number.isInteger(meta.last_page) ||
        !Number.isInteger(meta.rating_count) ||
        (meta.rating_average !== null && !Number.isFinite(meta.rating_average))
    ) {
        throw new Error('Invalid movie reviews response.');
    }

    return { reviews: data, meta };
}

export async function deleteMovieReview(reviewId, { signal } = {}) {
    await prepareReviewWrite(signal);
    await api.delete(`/v1/reviews/${encodeURIComponent(reviewId)}`, { signal });
}