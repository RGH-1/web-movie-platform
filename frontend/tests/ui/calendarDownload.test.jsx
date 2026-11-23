import { afterEach, expect, it, vi } from 'vitest';
import { downloadCalendar } from '../../src/utils/downloadCalendar.js';

afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});

it('downloads a calendar Blob and releases the temporary browser resources', () => {
    vi.useFakeTimers();

    const createObjectURL = vi.fn(() => 'blob:calendar-test');
    const revokeObjectURL = vi.fn();
    vi.stubGlobal('URL', { createObjectURL, revokeObjectURL });

    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click')
        .mockImplementation(function () {
            expect(this.isConnected).toBe(true);
            expect(this.download).toBe('movie-night-7.ics');
        });

    downloadCalendar(
        'BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n',
        'movie-night-7.ics',
    );

    expect(click).toHaveBeenCalledTimes(1);
    const blob = createObjectURL.mock.calls[0][0];
    expect(blob).toBeInstanceOf(Blob);
    expect(blob.type).toBe('text/calendar;charset=utf-8');
    expect(document.querySelector('a[download]')).toBeNull();

    vi.runAllTimers();
    expect(revokeObjectURL).toHaveBeenCalledWith('blob:calendar-test');
});