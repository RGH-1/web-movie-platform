export function downloadCalendar(content, filename) {
    if (!/^[a-z0-9-]+\.ics$/i.test(filename)) {
        throw new Error('Invalid calendar filename.');
    }

    const blob = new Blob([content], {
        type: 'text/calendar;charset=utf-8',
    });
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');

    anchor.href = url;
    anchor.download = filename;

    try {
        document.body.append(anchor);
        anchor.click();
    } finally {
        anchor.remove();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    }
}