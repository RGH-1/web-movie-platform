import api from './api';

function readProfile(body) {
  const profile = body?.data;
  if (profile == null) return null;

  if (
    typeof profile !== 'object' || Array.isArray(profile) ||
    !Number.isInteger(profile.id) ||
    typeof profile.name !== 'string' || typeof profile.email !== 'string' ||
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

export async function getProfile({ signal } = {}) {
  const response = await api.get('/v1/me/profile', { signal });
  return readProfile(response.data);
}

export async function updateProfile(fields, { signal } = {}) {
  const csrfUrl = new URL('/sanctum/csrf-cookie', api.defaults.baseURL).href;
  await api.get(csrfUrl, { signal });

  // Explicit allowlist: never send an entire user model back to Laravel.
  const payload = Object.fromEntries(
    ['name', 'email', 'bio', 'favorite_genres', 'current_password']
      .filter((field) => Object.hasOwn(fields, field))
      .map((field) => [field, fields[field]]),
  );
  const response = await api.patch('/v1/me/profile', payload, { signal });
  const profile = readProfile(response.data);
  if (!profile) throw new Error('The save response did not include a profile.');
  return profile;
}
