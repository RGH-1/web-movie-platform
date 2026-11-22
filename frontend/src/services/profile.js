import api from './api';

function readProfile(body) {
  const profile = body?.data;
  if (profile == null) return null;

  if (
    typeof profile !== 'object' || Array.isArray(profile) ||
    !Number.isInteger(profile.id) ||
    typeof profile.name !== 'string' || (profile.email !== null && typeof profile.email !== 'string') ||
    (profile.bio != null && typeof profile.bio !== 'string') ||
    (profile.favorite_genres != null &&
      (!Array.isArray(profile.favorite_genres) ||
        !profile.favorite_genres.every(
          (genre) => Number.isInteger(genre) && genre > 0,
        )))
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
    ['name', 'email', 'bio', 'favorite_genres', 'share_activity', 'current_password']
      .filter((field) => Object.hasOwn(fields, field))
      .map((field) => [field, fields[field]]),
  );
  const response = await api.patch('/v1/me/profile', payload, { signal });
  const profile = readProfile(response.data);
  if (!profile) throw new Error('The save response did not include a profile.');
  return profile;
}

export async function getPublicProfile(userId, { signal } = {}) {
  const response = await api.get(
    `/v1/users/${encodeURIComponent(userId)}/profile`,
    { signal },
  );
  const profile = response.data?.data;

  if (profile?.id !== userId || typeof profile.name !== 'string') {
    throw new Error('Invalid public profile response.');
  }

  return {
    id: profile.id, name: profile.name,
    followers_count: profile.followers_count ?? 0,
    following_count: profile.following_count ?? 0,
    is_following: profile.is_following === true,
    statistics: profile.statistics,
    reviews: profile.reviews ?? [],
    public_lists: profile.public_lists ?? [],
  };
}

export async function getUserRelationshipList(userId, relationship, page = 1, { signal } = {}) {
  if (!['followers', 'following'].includes(relationship)) {
    throw new Error('Unsupported user relationship.');
  }

  const response = await api.get(
    `/v1/users/${encodeURIComponent(userId)}/${relationship}`,
    { params: { page }, signal },
  );
  const users = response.data?.data;
  const meta = response.data?.meta;

  if (!Array.isArray(users) || !Number.isInteger(meta?.current_page) ||
    !Number.isInteger(meta?.last_page) || meta.current_page < 1 || meta.last_page < 1 ||
    !users.every((user) => user && typeof user.id === 'string' && typeof user.name === 'string' &&
      Number.isInteger(user.followers_count) && Number.isInteger(user.following_count) &&
      typeof user.is_following === 'boolean')) {
    throw new Error('Invalid relationship list response.');
  }

  return { data: users, meta };
}

export async function setUserFollow(userId, following, { signal } = {}) {
  const csrfUrl = new URL('/sanctum/csrf-cookie', api.defaults.baseURL).href;
  await api.get(csrfUrl, { signal });
  const path = `/v1/users/${encodeURIComponent(userId)}/follow`;
  const response = following
    ? await api.post(path, null, { signal })
    : await api.delete(path, { signal });
  const data = response.data?.data;
  if (data?.id !== userId || typeof data.is_following !== 'boolean' ||
    !Number.isInteger(data.followers_count) || !Number.isInteger(data.following_count)) {
    throw new Error('Invalid follow response.');
  }
  return {
    is_following: data.is_following,
    followers_count: data.followers_count,
    following_count: data.following_count,
  };
}
