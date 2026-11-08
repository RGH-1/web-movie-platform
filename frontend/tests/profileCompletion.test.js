import test from 'node:test';
import assert from 'node:assert/strict';
import {
  readProfileCompletion, completionFromError, completionFieldLabel,
} from '../src/utils/profileCompletion.js';

test('uses backend status even when optional fields are empty', () => {
  assert.deepEqual(readProfileCompletion({
    profile_complete: true, incomplete_fields: [], bio: null, favorite_genres: [],
  }), { complete: true, fields: [] });
});

test('preserves backend incompleteness without calculating it from profile fields', () => {
  const meta = { profile_complete: false, incomplete_fields: ['name', 'email', 'name'] };
  assert.deepEqual(readProfileCompletion(meta), { complete: false, fields: ['name', 'email'] });
  assert.deepEqual(meta.incomplete_fields, ['name', 'email', 'name']);
});

test('missing or malformed metadata remains unknown rather than declaring completion', () => {
  for (const meta of [null, {}, { profile_complete: 'false', incomplete_fields: [] },
    { profile_complete: false, incomplete_fields: [null] }]) {
    assert.equal(readProfileCompletion(meta), null);
  }
});

test('recognizes only explicit backend completion denials', () => {
  assert.deepEqual(completionFromError({ response: {
    status: 403, data: { code: 'PROFILE_INCOMPLETE', incomplete_fields: ['email'] },
  } }), { complete: false, fields: ['email'] });
});

test('does not reinterpret unrelated authorization, authentication or network errors', () => {
  for (const error of [{}, { response: { status: 401, data: { code: 'PROFILE_INCOMPLETE' } } },
    { response: { status: 403, data: { code: 'FORBIDDEN' } } },
    { response: { status: 500, data: { code: 'PROFILE_INCOMPLETE' } } }]) {
    assert.equal(completionFromError(error), null);
  }
});

test('a completion denial without field details still provides generic guidance', () => {
  assert.deepEqual(completionFromError({ response: {
    status: 403, data: { code: 'PROFILE_INCOMPLETE' },
  } }), { complete: false, fields: [] });
});

test('unknown field names use generic user-facing wording', () => {
  assert.equal(completionFieldLabel('name'), 'Name');
  assert.equal(completionFieldLabel('email'), 'Email');
  assert.equal(completionFieldLabel('internal_key'), 'Required profile information');
});
