import api from './api';

const endpoint = '/v1/movie-nights';

export async function getMovieNights(page, { signal } = {}) {
    const response = await api.get(endpoint, { params: { page }, signal });
    return response.data;
}

export async function getMovieNight(id, { signal } = {}) {
    const response = await api.get(`${endpoint}/${encodeURIComponent(id)}`, { signal });
    return response.data.data;
}

async function writeMovieNight(method, url, data, signal) {
    await api.get(new URL('/sanctum/csrf-cookie', api.defaults.baseURL).href, { signal });
    const response = await api.request({ method, url, data, signal });
    return response.data.data;
}

export function saveMovieNight(id, fields, { signal } = {}) {
    return id
        ? writeMovieNight('patch', `${endpoint}/${encodeURIComponent(id)}`, fields, signal)
        : writeMovieNight('post', endpoint, fields, signal);
}

export function cancelMovieNight(id, { signal } = {}) {
    return writeMovieNight('post', `${endpoint}/${encodeURIComponent(id)}/cancel`, undefined, signal);
}

export async function getInvitations(id, { signal } = {}) {
    const response = await api.get(`${endpoint}/${encodeURIComponent(id)}/invitations`, { signal });
    return response.data.data;
}

export async function getMyMovieNightInvitations(page = 1, { signal } = {}) {
    const response = await api.get('/v1/me/movie-night-invitations', { params: { page, per_page: 50 }, signal });
    return response.data;
}

export function inviteToMovieNight(id, inviteeId, { signal } = {}) {
    return writeMovieNight('post', `${endpoint}/${encodeURIComponent(id)}/invitations`, { invitee_id: String(inviteeId) }, signal);
}

export function respondToInvitation(id, status, { signal } = {}) {
    return writeMovieNight('patch', `${endpoint}/${encodeURIComponent(id)}/rsvp`, { status }, signal);
}

export async function getMovieNightCandidates(id, { signal } = {}) {
    const response = await api.get(`${endpoint}/${encodeURIComponent(id)}/candidates`, { signal });
    return response.data.data;
}

export function proposeMovieNightCandidate(id, movieId, { signal } = {}) {
    return writeMovieNight('post', `${endpoint}/${encodeURIComponent(id)}/candidates`, { movie_id: String(movieId) }, signal);
}

export function voteMovieNightCandidate(id, candidateId, { signal } = {}) {
    return writeMovieNight('put', `${endpoint}/${encodeURIComponent(id)}/vote`, { candidate_id: String(candidateId) }, signal);
}

export function finalizeMovieNight(id, candidateId, { signal } = {}) {
    const data = candidateId == null ? {} : { candidate_id: String(candidateId) };
    return writeMovieNight('post', `${endpoint}/${encodeURIComponent(id)}/finalize`, data, signal);
}

export async function searchCatalogMovies(search, page = 1, { signal } = {}) {
    const response = await api.get('/v1/catalog/movies', { params: { search, page }, signal });
    return response.data;
}
