import api from './api.js';

export async function saveMovieReview({
    movieId,
    reviewId,
    rating,
    reviewText,
    signal,
}) {
    const csrfUrl = new URL('/sanctum/csrf-cookie', api.defaults.baseURL).href;
    await api.get(csrfUrl, { signal });

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