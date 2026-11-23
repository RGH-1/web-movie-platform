import api from './api';

export async function getAdminReviews({ page, status }, { signal } = {}) {
    const response = await api.get('/v1/admin/reviews', { params: { page, status: status || undefined }, signal });
    return response.data;
}

export async function moderateReview(id, fields, { signal } = {}) {
    await api.get(new URL('/sanctum/csrf-cookie', api.defaults.baseURL).href, { signal });
    const response = await api.patch(`/v1/admin/reviews/${encodeURIComponent(id)}/moderation`, fields, { signal });
    return response.data.data;
}
