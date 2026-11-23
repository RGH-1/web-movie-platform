export const movieFields = [
    { name: 'title', label: 'Title', required: true, maxLength: 255 },
    { name: 'overview', label: 'Overview', rows: 4, maxLength: 10000 },
    { name: 'release_date', label: 'Release date', type: 'date' },
    { name: 'runtime_minutes', label: 'Runtime (minutes)', type: 'number' },
    { name: 'director', label: 'Director', maxLength: 255 },
    { name: 'poster_url', label: 'Poster URL', type: 'url', maxLength: 2048 },
    { name: 'backdrop_url', label: 'Backdrop URL', type: 'url', maxLength: 2048 },
    { name: 'trailer', label: 'YouTube video ID', maxLength: 11 },
];

export const movieCollections = [
    { name: 'genres', title: 'Genres', item: 'genre', fields: [
        { name: 'name', label: 'Genre name', required: true, maxLength: 100 },
        { name: 'tmdb_id', label: 'TMDB genre ID (optional)', type: 'number' },
    ] },
    { name: 'cast', title: 'Cast', item: 'cast member', fields: [
        { name: 'name', label: 'Actor name', required: true, maxLength: 255 },
        { name: 'character', label: 'Character', maxLength: 255 },
        { name: 'tmdb_person_id', label: 'TMDB person ID (optional)', type: 'number' },
        { name: 'profile_url', label: 'Profile image URL', type: 'url', maxLength: 2048 },
    ] },
];

export function movieFormValues(movie) {
    return {
        ...Object.fromEntries(movieFields.map(({ name }) => [name, String(
            (name === 'trailer' ? movie?.trailer?.key : movie?.[name]) ?? '',
        )])),
        ...Object.fromEntries(movieCollections.map(({ name, fields }) => [
            name, (movie?.[name] ?? []).map((entry) => Object.fromEntries(
                fields.map((field) => [field.name, String(entry[field.name] ?? '')]),
            )),
        ])),
    };
}

function parseField(raw, field, key, errors) {
    const value = raw.trim();
    if (!value) {
        if (field.required) errors[key] = `Enter ${field.label.toLowerCase()}.`;
        return null;
    }
    if (field.maxLength && Array.from(value).length > field.maxLength) {
        errors[key] = `Use at most ${field.maxLength} characters.`;
    }

    if (field.type === 'number') {
        const number = Number(value);
        if (!Number.isInteger(number) || number < 1 || number > 2147483647) {
            errors[key] = 'Enter a whole number between 1 and 2147483647.';
        }
        return number;
    }
    if (field.type === 'url' && (!value.startsWith('https://') || !URL.canParse(value))) {
        errors[key] = 'Enter a valid HTTPS URL.';
    }
    if (field.name === 'trailer') {
        if (!/^[\w-]{11}$/.test(value)) errors[key] = 'Enter the 11-character YouTube video ID.';
        return { site: 'YouTube', key: value };
    }
    return value;
}

export function movieChanges(values, initial, creating) {
    const payload = {};
    const errors = {};
    for (const field of movieFields) {
        const { name } = field;
        if ((creating && name === 'title') || values[name].trim() !== initial[name].trim()) {
            const parsed = parseField(values[name], field, name, errors);
            const original = parseField(initial[name], field, name, {});
            if ((creating && name === 'title') || JSON.stringify(parsed) !== JSON.stringify(original)) {
                payload[name] = parsed;
            }
        }
    }

    for (const { name, fields } of movieCollections) {
        if (JSON.stringify(values[name]) === JSON.stringify(initial[name])) continue;
        const parseRows = (rows, messages) => rows.map((row, index) => Object.fromEntries(fields.map((field) => [
            field.name, parseField(row[field.name], field, `${name}.${index}.${field.name}`, messages),
        ])));
        const parsed = parseRows(values[name], errors);
        if (JSON.stringify(parsed) !== JSON.stringify(parseRows(initial[name], {}))) {
            payload[name] = parsed;
        }
    }
    return { payload, errors };
}
