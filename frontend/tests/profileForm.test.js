import test from 'node:test';
import assert from 'node:assert/strict';
import {
  profileToForm, emailIsChanging, validateProfileForm, buildProfileUpdate,
} from '../src/utils/profileForm.js';

const profile = {
  id: 1, name: 'Sam', email: 'sam@example.com', bio: null, favorite_genres: [18],
};

test('editing prefills fields without mutating saved profile', () => {
  const form = profileToForm(profile);
  assert.equal(form.bio, '');
  assert.equal(form.current_password, '');
  form.favorite_genres.push(35);
  assert.deepEqual(profile.favorite_genres, [18]);
});

test('normalizes editable fields and clears blank optional values', () => {
  const form = {
    ...profileToForm(profile), name: ' Sam Updated ', email: ' SAM@EXAMPLE.COM ',
    bio: '   ', favorite_genres: [], role: 'admin', id: 99, current_password: 'unused',
  };
  assert.deepEqual(validateProfileForm(form, profile), {});
  assert.deepEqual(buildProfileUpdate(form, profile), {
    name: 'Sam Updated', bio: null, favorite_genres: [],
  });
});

test('email changes require password and preserve its exact whitespace', () => {
  const form = { ...profileToForm(profile), email: 'NEW@EXAMPLE.COM' };
  assert.equal(emailIsChanging(form, profile), true);
  assert.ok(validateProfileForm(form, profile).current_password);
  form.current_password = ' spaced passphrase ';
  assert.deepEqual(validateProfileForm(form, profile), {});
  assert.equal(buildProfileUpdate(form, profile).current_password, ' spaced passphrase ');
  assert.equal(buildProfileUpdate(form, profile).email, 'new@example.com');
});

test('client rejects missing names and invalid emails without imposing a dotted-domain rule', () => {
  const form = { ...profileToForm(profile), name: ' ', email: 'not-an-email' };
  const errors = validateProfileForm(form, profile);
  assert.ok(errors.name);
  assert.ok(errors.email);
  assert.equal(validateProfileForm({ ...form, name: 'Sam', email: 'sam@localhost' }, profile).email, undefined);
});

test('length limits count Unicode code points', () => {
  const form = { ...profileToForm(profile), name: '😀'.repeat(255), bio: '😀'.repeat(500) };
  assert.deepEqual(validateProfileForm(form, profile), {});
  form.name += 'x';
  form.bio += 'x';
  const errors = validateProfileForm(form, profile);
  assert.ok(errors.name);
  assert.ok(errors.bio);
});

test('genre rules accept empty or five choices and reject invalid selections', () => {
  const form = profileToForm(profile);
  for (const favorite_genres of [[], [28, 18, 35, 80, 9648]]) {
    assert.deepEqual(validateProfileForm({ ...form, favorite_genres }, profile), {});
  }
  for (const favorite_genres of [
    [18, 18], ['unknown'],
    [28, 18, 35, 80, 9648, 27],
  ]) {
    assert.ok(validateProfileForm({ ...form, favorite_genres }, profile).favorite_genres);
  }
});

test('reverting email omits a previously entered password', () => {
  const form = { ...profileToForm(profile), email: 'new@example.com', current_password: 'secret' };
  assert.ok(Object.hasOwn(buildProfileUpdate(form, profile), 'current_password'));
  form.email = ' SAM@EXAMPLE.COM ';
  assert.equal(emailIsChanging(form, profile), false);
  assert.equal(Object.hasOwn(buildProfileUpdate(form, profile), 'current_password'), false);
});

test('a missing email remains absent while unrelated Facebook profile fields are updated', () => {
  const facebookProfile = { ...profile, email: null };
  const form = { ...profileToForm(facebookProfile), name: ' Updated Sam ', bio: 'Movie fan' };

  assert.equal(emailIsChanging(form, facebookProfile), false);
  assert.deepEqual(validateProfileForm(form, facebookProfile), {});
  assert.deepEqual(buildProfileUpdate(form, facebookProfile), {
    name: 'Updated Sam', bio: 'Movie fan', favorite_genres: [18],
  });
});
