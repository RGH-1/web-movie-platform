import api from './api';

export async function getAdminDashboard({ signal } = {}) {
    const response = await api.get('/v1/admin/dashboard', { signal });
    return response.data.data;
}
