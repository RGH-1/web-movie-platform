export const GENRES = {
  action: 'Action',
  adventure: 'Adventure',
  animation: 'Animation',
  comedy: 'Comedy',
  crime: 'Crime',
  documentary: 'Documentary',
  drama: 'Drama',
  fantasy: 'Fantasy',
  horror: 'Horror',
  mystery: 'Mystery',
  romance: 'Romance',
  'science-fiction': 'Science fiction',
  thriller: 'Thriller',
};

export function profileToForm(profile) {
  return {
    name: profile.name,
    email: profile.email,
    bio: profile.bio ?? '',
    favorite_genres: [...profile.favorite_genres],
    current_password: '',
  };
}

export function emailIsChanging(form, profile) {
  return form.email.trim().toLowerCase() !== profile.email;
}

export function validateProfileForm(form, profile) {
  const errors = {};
  const length = (value) => [...value].length;
  const name = form.name.trim();
  const email = form.email.trim().toLowerCase();

  if (!name || length(name) > 255) {
    errors.name = ['Enter a name between 1 and 255 characters.'];
  }
  // Laravel remains authoritative for RFC syntax and uniqueness.
  if (!email || length(email) > 255 || !/^[^\s@]+@[^\s@]+$/.test(email)) {
    errors.email = ['Enter a valid email address of at most 255 characters.'];
  }
  if (length(form.bio.trim()) > 500) {
    errors.bio = ['Your bio must be at most 500 characters.'];
  }
  if (
    form.favorite_genres.length > 5 ||
    new Set(form.favorite_genres).size !== form.favorite_genres.length ||
    form.favorite_genres.some((genre) => !Object.hasOwn(GENRES, genre))
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
    email: form.email.trim().toLowerCase(),
    bio: form.bio.trim() || null,
    favorite_genres: [...form.favorite_genres],
  };
  if (emailIsChanging(form, profile)) {
    // Passwords must never be trimmed or normalized.
    update.current_password = form.current_password;
  }
  return update;
}
