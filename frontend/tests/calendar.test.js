import test from 'node:test';
import assert from 'node:assert/strict';
import {
    buildMovieNightIcs,
    buildReleaseIcs,
    isUpcomingRelease,
    formatMovieNightStart,
} from '../src/utils/calendar.js';

const options = {
    sourceUrl: 'http://localhost:8000/api',
    now: new Date('2026-11-22T12:00:00Z'),
};

const night = {
    id: '9007199254740993',
    title: 'Movie night',
    description: 'Bring snacks.',
    starts_at: '2026-11-29T20:00:00+02:00',
    timezone: 'Asia/Beirut',
    location: 'Room 2',
    meeting_url: 'https://example.test/meeting?room=2',
    status: 'scheduled',
    updated_at: '2026-11-22T10:00:00.000000Z',
};

const movie = {
    tmdb_id: 123,
    local_movie_id: null,
    title: 'Upcoming movie',
    overview: 'A release reminder.',
    release_date: '2026-11-29',
};

const unfold = (value) => value.replace(/\r\n[ \t]/g, '');

test('movie night preserves the instant, timezone, location and meeting link', () => {
    const content = unfold(buildMovieNightIcs(night, options));

    assert.ok(content.includes('DTSTART:20261129T180000Z\r\n'));
    assert.ok(content.includes('Event timezone: Asia/Beirut'));
    assert.ok(content.includes('20:00'));
    assert.ok(content.includes('LOCATION:Room 2\r\n'));
    assert.ok(content.includes('URL:https://example.test/meeting?room=2\r\n'));
    assert.ok(content.includes('LAST-MODIFIED:20261122T100000Z\r\n'));
    assert.ok(content.includes('CLASS:PRIVATE\r\n'));
    assert.ok(!content.includes('DTEND'));
    assert.ok(!content.includes('TZID='));
    assert.ok(content.endsWith('END:VCALENDAR\r\n'));

    const withoutCrLf = content.replaceAll('\r\n', '');
    assert.ok(!/[\r\n]/.test(withoutCrLf));
});

test('UID is stable across repeated exports and schedule edits', () => {
    const extract = (content) => unfold(content).split('\r\n')
        .find((line) => line.startsWith('UID:'));

    const original = extract(buildMovieNightIcs(night, options));
    const changed = extract(buildMovieNightIcs({
        ...night,
        starts_at: '2026-12-01T18:00:00Z',
    }, {
        ...options,
        now: new Date('2026-11-23T12:00:00Z'),
    }));

    assert.equal(original, changed);
    assert.ok(original.includes('9007199254740993'));
});

test('text escaping prevents injected properties and folds Unicode by bytes', () => {
    const content = buildMovieNightIcs({
        ...night,
        title: 'Café, film; night\\crew\nBEGIN:VEVENT\nSUMMARY:injected',
        description: 'é🎬'.repeat(120),
    }, options);

    for (const line of content.split('\r\n')) {
        assert.ok(new TextEncoder().encode(line).length <= 75);
    }

    const lines = unfold(content).split('\r\n');
    assert.equal(lines.filter((line) => line === 'BEGIN:VEVENT').length, 1);
    assert.ok(lines.some((line) =>
        line.startsWith('SUMMARY:Café\\, film\\; night\\\\crew\\nBEGIN:VEVENT')));
    assert.ok(!lines.includes('SUMMARY:injected'));
    assert.ok(unfold(content).includes('é🎬'.repeat(120)));
});

test('UTC instants remain correct across a daylight-saving transition', () => {
    const before = {
        ...night,
        timezone: 'America/New_York',
        starts_at: '2027-03-14T06:30:00Z',
    };
    const after = { ...before, starts_at: '2027-03-14T07:30:00Z' };

    assert.ok(formatMovieNightStart(before).includes('01:30'));
    assert.ok(formatMovieNightStart(after).includes('03:30'));
    assert.ok(buildMovieNightIcs(before, options)
        .includes('DTSTART:20270314T063000Z'));
    assert.ok(buildMovieNightIcs(after, options)
        .includes('DTSTART:20270314T073000Z'));
});

test('drafts, cancelled nights, naive times and unsafe URLs are rejected', () => {
    for (const status of ['draft', 'cancelled']) {
        assert.throws(() => buildMovieNightIcs({ ...night, status }, options));
    }

    assert.throws(() => buildMovieNightIcs({
        ...night,
        starts_at: '2026-11-29T20:00:00',
    }, options));

    assert.throws(() => buildMovieNightIcs({
        ...night,
        meeting_url: 'javascript:alert(1)',
    }, options));

    assert.throws(() => buildMovieNightIcs({
        ...night,
        meeting_url: 'https://example.test/\r\nSUMMARY:injected',
    }, options));
});

test('release events use all-day dates with an exclusive next-day end', () => {
    const content = unfold(buildReleaseIcs(movie, options));

    assert.ok(content.includes('DTSTART;VALUE=DATE:20261129\r\n'));
    assert.ok(content.includes('DTEND;VALUE=DATE:20261130\r\n'));
    assert.ok(content.includes('TRANSP:TRANSPARENT\r\n'));
    assert.ok(!content.includes('TZID'));
});

test('release date arithmetic handles leap days and year boundaries', () => {
    const leap = buildReleaseIcs({
        ...movie,
        release_date: '2028-02-29',
    }, options);
    const year = buildReleaseIcs({
        ...movie,
        release_date: '2030-12-31',
    }, options);

    assert.ok(leap.includes('DTEND;VALUE=DATE:20280301'));
    assert.ok(year.includes('DTEND;VALUE=DATE:20310101'));
});

test('missing, impossible, past and inactive releases are unavailable', () => {
    for (const release_date of [null, '2026-02-30', '2020-01-01']) {
        const value = { ...movie, release_date };
        assert.equal(isUpcomingRelease(value, options.now), false);
        assert.throws(() => buildReleaseIcs(value, options));
    }

    assert.equal(isUpcomingRelease({
        ...movie,
        catalog_deactivated: true,
    }, options.now), false);
});

test('local-only release identity remains a decimal string', () => {
    const content = unfold(buildReleaseIcs({
        ...movie,
        tmdb_id: null,
        local_movie_id: '9007199254740993',
    }, options));

    assert.ok(content.includes('release-local-9007199254740993-'));
});