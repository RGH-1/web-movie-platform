export const GENRES = Object.freeze({
  28: 'Action',
  12: 'Adventure',
  16: 'Animation',
  35: 'Comedy',
  80: 'Crime',
  99: 'Documentary',
  18: 'Drama',
  10751: 'Family',
  14: 'Fantasy',
  36: 'History',
  27: 'Horror',
  10402: 'Music',
  9648: 'Mystery',
  10749: 'Romance',
  878: 'Science Fiction',
  10770: 'TV Movie',
  53: 'Thriller',
  10752: 'War',
  37: 'Western',
});

export const GENRE_OPTIONS = Object.entries(GENRES)
  .map(([id, label]) => ({ id: Number(id), label }))
  .sort((left, right) => left.label.localeCompare(right.label));

export function profileToForm(profile) {
  return {
    name: profile.name,
    // Keep null in the API model, but use an empty input value for the form.
    email: profile.email ?? '',
    bio: profile.bio ?? '',
    favorite_genres: [...(profile.favorite_genres ?? [])],
    current_password: '',
  };
}

export function emailIsChanging(form, profile) {
  return form.email.trim().toLowerCase() !== (profile.email ?? '').trim().toLowerCase();
}

export function validateProfileForm(form, profile) {
  const errors = {};
  const length = (value) => [...value].length;
  const name = form.name.trim();
  const email = form.email.trim().toLowerCase();

  if (!name || length(name) > 255) {
    errors.name = ['Enter a name between 1 and 255 characters.'];
  }
  // Email is optional only while it remains the missing Facebook value.
  // Laravel remains authoritative for RFC syntax and uniqueness when changed.
  if (emailIsChanging(form, profile) && (!email || length(email) > 255 || !/^[^\s@]+@[^\s@]+$/.test(email))) {
    errors.email = ['Enter a valid email address of at most 255 characters.'];
  }
  if (length(form.bio.trim()) > 500) {
    errors.bio = ['Your bio must be at most 500 characters.'];
  }
  if (
    !Array.isArray(form.favorite_genres) ||
    form.favorite_genres.length > 5 ||
    new Set(form.favorite_genres).size !== form.favorite_genres.length ||
    form.favorite_genres.some(
      (genre) => !Number.isInteger(genre) || !Object.hasOwn(GENRES, genre),
    )
  ) {
    errors.favorite_genres = ['Choose up to five different supported genres.'];
  }
  if (emailIsChanging(form, profile) && !form.current_password) {
    errors.current_password = ['Enter your current password to change your email.'];
  }
  return errors;
}

export function buildProfileUpdate(form, profile) {
  const update = {
    name: form.name.trim(),
    bio: form.bio.trim() || null,
    favorite_genres: [...form.favorite_genres],
  };
  if (emailIsChanging(form, profile)) {
    update.email = form.email.trim().toLowerCase();
    // Passwords must never be trimmed or normalized.
    update.current_password = form.current_password;
  }
  return update;
}
