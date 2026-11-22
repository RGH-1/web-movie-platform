import api from './api';

export async function getRecommendations(coordinates, { signal } = {}) {
    const response = await api.get('/v1/recommendations', { params: coordinates, signal });
    return response.data;
}

export async function resolveRecommendationCity(city, { signal } = {}) {
    const response = await api.get('/v1/recommendations/city', { params: { city }, signal });
    return response.data.data;
}
