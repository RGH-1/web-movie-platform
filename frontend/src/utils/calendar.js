const encoder = new TextEncoder();

const INSTANT = /^\d{4}-\d{2}-\d{2}T(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d{1,6})?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)$/;

export function isCanonicalId(value) {
    return typeof value === 'string' &&
        /^[1-9][0-9]{0,18}$/.test(value) &&
        (value.length < 19 || value <= '9223372036854775807');
}

function requiredText(value) {
    if (typeof value !== 'string' || !value.trim()) {
        throw new Error('Missing calendar text.');
    }
    return value.trim();
}

function optionalText(value) {
    if (value == null) return '';
    if (typeof value !== 'string') {
        throw new Error('Invalid calendar text.');
    }
    return value.trim();
}

function parseDay(value) {
    if (typeof value !== 'string' ||
        !/^\d{4}-\d{2}-\d{2}$/.test(value) ||
        Number(value.slice(0, 4)) < 1) {
        throw new Error('Invalid calendar date.');
    }

    const date = new Date(value + 'T00:00:00Z');

    if (!Number.isFinite(date.getTime()) ||
        date.toISOString().slice(0, 10) !== value) {
        throw new Error('Invalid calendar date.');
    }

    return date;
}

function dateValue(value) {
    parseDay(value);
    return value.replaceAll('-', '');
}

function nextDay(value) {
    const date = parseDay(value);
    date.setUTCDate(date.getUTCDate() + 1);
    const result = date.toISOString().slice(0, 10);
    parseDay(result);
    return result;
}

function instantDate(value) {
    if (value instanceof Date) {
        if (!Number.isFinite(value.getTime())) {
            throw new Error('Invalid calendar timestamp.');
        }
        return value;
    }

    if (typeof value !== 'string' || !INSTANT.test(value)) {
        throw new Error('An explicit-offset timestamp is required.');
    }

    parseDay(value.slice(0, 10));

    // Laravel returns microseconds; normalize fractions for browser parsing.
    const normalized = value.replace(
        /\.(\d+)(?=Z|[+-])/,
        (_, digits) => '.' + digits.padEnd(3, '0').slice(0, 3),
    );
    const date = new Date(normalized);

    if (!Number.isFinite(date.getTime())) {
        throw new Error('Invalid calendar timestamp.');
    }

    return date;
}

function timestamp(value) {
    const iso = instantDate(value).toISOString();

    if (!/^\d{4}-/.test(iso) || Number(iso.slice(0, 4)) < 1) {
        throw new Error('Unsupported calendar year.');
    }

    return iso.replaceAll('-', '').replaceAll(':', '')
        .replace(/\.\d{3}Z$/, 'Z');
}

export function safeHttpUrl(value) {
    if (value == null || value === '') return null;

    if (typeof value !== 'string' ||
        /[\u0000-\u0020\u007f]/.test(value)) {
        throw new Error('Invalid calendar URL.');
    }

    const url = new URL(value);

    if (!['http:', 'https:'].includes(url.protocol)) {
        throw new Error('Unsupported calendar URL.');
    }

    return url.href;
}

function escapeText(value) {
    return value
        .replace(/\r\n?/g, '\n')
        .replace(/[\u0000-\u0008\u000b\u000c\u000e-\u001f\u007f]/g, '')
        .replaceAll('\\', '\\\\')
        .replaceAll('\n', '\\n')
        .replaceAll(';', '\\;')
        .replaceAll(',', '\\,');
}

function foldLine(line) {
    let result = '';
    let bytes = 0;

    for (const character of line) {
        const length = encoder.encode(character).length;

        if (bytes + length > 75) {
            result += '\r\n ';
            bytes = 1;
        }

        result += character;
        bytes += length;
    }

    return result;
}

function uid(kind, id, sourceUrl) {
    const source = safeHttpUrl(sourceUrl);
    if (!source) throw new Error('Missing calendar namespace.');

    const namespace = encodeURIComponent(new URL(source).origin);

    return `${kind}-${id}-${namespace}@web-movie-platform.invalid`;
}

function calendar(properties, now) {
    const lines = [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//Web Movie Platform//Calendar Export//EN',
        'CALSCALE:GREGORIAN',
        'BEGIN:VEVENT',
        `DTSTAMP:${timestamp(now)}`,
        ...properties,
        'END:VEVENT',
        'END:VCALENDAR',
    ];

    return lines.map(foldLine).join('\r\n') + '\r\n';
}

export function formatMovieNightStart(night) {
    const timezone = requiredText(night.timezone);

    const formatted = new Intl.DateTimeFormat('en-GB', {
        timeZone: timezone,
        dateStyle: 'full',
        timeStyle: 'short',
    }).format(instantDate(night.starts_at));

    return `${formatted} (${timezone})`;
}

export function buildMovieNightIcs(
    night,
    { sourceUrl, now = new Date() } = {},
) {
    if (!isCanonicalId(night.id)) {
        throw new Error('Invalid movie-night ID.');
    }

    if (!['scheduled', 'finalized'].includes(night.status)) {
        throw new Error('Movie night is not scheduled.');
    }

    const title = requiredText(night.title);
    const start = timestamp(night.starts_at);
    const localStart = formatMovieNightStart(night);
    const meeting = safeHttpUrl(night.meeting_url);
    const location = optionalText(night.location);

    const description = [
        optionalText(night.description),
        `Event timezone: ${night.timezone}`,
        `Local start: ${localStart}`,
        meeting ? `Meeting link: ${meeting}` : '',
    ].filter(Boolean).join('\n\n');

    const properties = [
        `UID:${uid('movie-night', night.id, sourceUrl)}`,
        `DTSTART:${start}`,
        `SUMMARY:${escapeText(title)}`,
        `DESCRIPTION:${escapeText(description)}`,
        'STATUS:CONFIRMED',
        'CLASS:PRIVATE',
        'TRANSP:OPAQUE',
    ];

    if (location) properties.push(`LOCATION:${escapeText(location)}`);
    if (meeting) properties.push(`URL:${meeting}`);

    if (night.updated_at != null) {
        properties.push(`LAST-MODIFIED:${timestamp(night.updated_at)}`);
    }

    return calendar(properties, now);
}

export function releaseKey(movie) {
    if (Number.isInteger(movie.tmdb_id) &&
        movie.tmdb_id > 0 && movie.tmdb_id <= 2147483647) {
        return `tmdb-${movie.tmdb_id}`;
    }

    if (isCanonicalId(movie.local_movie_id)) {
        return `local-${movie.local_movie_id}`;
    }

    throw new Error('Missing movie identity.');
}

export function isUpcomingRelease(movie, now = new Date()) {
    try {
        if (movie.catalog_deactivated === true || movie.is_active === false) {
            return false;
        }

        requiredText(movie.title);
        releaseKey(movie);
        parseDay(movie.release_date);

        return movie.release_date >= instantDate(now).toISOString().slice(0, 10);
    } catch {
        return false;
    }
}

export function buildReleaseIcs(
    movie,
    { sourceUrl, now = new Date() } = {},
) {
    if (!isUpcomingRelease(movie, now)) {
        throw new Error('No upcoming release date is available.');
    }

    const description = [
        `Release date: ${movie.release_date}`,
        optionalText(movie.overview),
    ].filter(Boolean).join('\n\n');

    return calendar([
        `UID:${uid('release', releaseKey(movie), sourceUrl)}`,
        `DTSTART;VALUE=DATE:${dateValue(movie.release_date)}`,
        `DTEND;VALUE=DATE:${dateValue(nextDay(movie.release_date))}`,
        `SUMMARY:${escapeText(requiredText(movie.title) + ' — release')}`,
        `DESCRIPTION:${escapeText(description)}`,
        'CLASS:PRIVATE',
        'TRANSP:TRANSPARENT',
    ], now);
}