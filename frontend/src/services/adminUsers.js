import api from './api';

export async function getAdminUsers({ page, search }, { signal } = {}) {
    const response = await api.get('/v1/admin/users', { params: { page, search: search || undefined }, signal });
    return response.data;
}

export async function updateAdminUserStatus(id, status, { signal } = {}) {
    await api.get(new URL('/sanctum/csrf-cookie', api.defaults.baseURL).href, { signal });
    const response = await api.patch(`/v1/admin/users/${encodeURIComponent(id)}/status`, { status }, { signal });
    return response.data.data;
}
