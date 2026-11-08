import api from './api';

/**
 * Read the signed-in account through Sanctum's session cookie.
 * The shared API client supplies credentials and CSRF configuration.
 */
export async function getProfile({ signal } = {}) {
  const response = await api.get('/v1/me/profile', {
    signal,
  });

  const profile = response.data?.data;

  if (profile == null) {
    return null;
  }

  if (
    typeof profile !== 'object' ||
    Array.isArray(profile) ||
    !Number.isInteger(profile.id) ||
    typeof profile.name !== 'string' ||
    typeof profile.email !== 'string' ||
    (profile.bio != null && typeof profile.bio !== 'string') ||
    (profile.favorite_genres != null &&
      (!Array.isArray(profile.favorite_genres) ||
        !profile.favorite_genres.every((genre) => typeof genre === 'string')))
  ) {
    throw new Error('Unexpected profile response.');
  }

  return {
    ...profile,
    bio: profile.bio ?? null,
    favorite_genres: profile.favorite_genres ?? [],
  };
}
