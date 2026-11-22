export const movieNightStatus = {
    draft: 'Draft',
    scheduled: 'Scheduled',
    finalized: 'Finalized',
    cancelled: 'Cancelled',
};

export function localDateTimeInput(date) {
    return new Date(date.getTime() - date.getTimezoneOffset() * 60_000).toISOString().slice(0, 16);
}

export function formatMovieNightStart({ starts_at, timezone }) {
    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: timezone,
    }).format(new Date(starts_at));
}

export function meetingLink(value) {
    if (!/^https?:\/\//i.test(value ?? '')) return null;
    try {
        return new URL(value).href;
    } catch {
        return null;
    }
}
