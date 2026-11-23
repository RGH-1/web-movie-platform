import { fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, expect, it, vi } from 'vitest';
import App from '../../src/App';
import CinemaMap from '../../src/components/CinemaMap';
import { useAuth } from '../../src/hooks/useAuth';
import api from '../../src/services/api';

vi.mock('../../src/components/CinemaMap', () => ({ default: vi.fn(() => null) }));
vi.mock('../../src/hooks/useAuth', () => ({ useAuth: vi.fn() }));
vi.mock('../../src/services/api', () => ({ default: { get: vi.fn() } }));

afterEach(() => {
    vi.resetAllMocks();
    vi.unstubAllGlobals();
});

const coordinates = { latitude: 33.8938, longitude: 35.5018 };
const cinema = {
    id: 'test-cinema',
    name: 'Test Cinema',
    address: 'Cinema Street, Beirut',
    latitude: 33.89,
    longitude: 35.5,
};

function openPage(isAuthenticated = true) {
    useAuth.mockReturnValue({ isAuthenticated, loading: false });
    render(<MemoryRouter initialEntries={['/cinemas/nearby']}><App /></MemoryRouter>);
}

function searchCity(city) {
    fireEvent.change(screen.getByLabelText('City'), { target: { value: city } });
    fireEvent.click(screen.getByRole('button', { name: 'Search city' }));
}

it('waits for a location click and supports city search after permission denial', async () => {
    const getCurrentPosition = vi.fn((_success, failure) => failure({ code: 1 }));
    vi.stubGlobal('navigator', { geolocation: { getCurrentPosition } });
    api.get.mockResolvedValueOnce({ data: { data: coordinates } })
        .mockResolvedValueOnce({ data: { data: [cinema] } });

    openPage();

    expect(getCurrentPosition).not.toHaveBeenCalled();
    expect(api.get).not.toHaveBeenCalled();

    fireEvent.click(screen.getByRole('button', { name: 'Use my location' }));
    expect(getCurrentPosition).toHaveBeenCalledOnce();
    expect(screen.getByRole('alert')).toHaveTextContent('Location permission was denied');

    searchCity('Beirut, Lebanon');

    expect(await screen.findByRole('heading', { name: cinema.name })).toBeInTheDocument();
    expect(screen.getByText(cinema.address)).toBeInTheDocument();
    expect(api.get).toHaveBeenNthCalledWith(1, '/v1/recommendations/city', {
        params: { city: 'Beirut, Lebanon' },
        signal: expect.any(AbortSignal),
    });
    expect(api.get).toHaveBeenNthCalledWith(2, '/v1/cinemas/nearby', {
        params: { lat: 33.8938, lng: 35.5018, radius: 10000 },
        signal: expect.any(AbortSignal),
    });
    expect(CinemaMap.mock.lastCall[0]).toEqual({ ...coordinates, cinemas: [cinema] });

    const directions = screen.getByRole('link', { name: /Directions to Test Cinema/ });
    expect(new URL(directions.href).searchParams.get('destination')).toBe('33.89,35.5');
});

it('searches with device coordinates and shows an empty result', async () => {
    const getCurrentPosition = vi.fn((success) => success({ coords: coordinates }));
    vi.stubGlobal('navigator', { geolocation: { getCurrentPosition } });
    api.get.mockResolvedValueOnce({ data: { data: [] } });

    openPage();
    fireEvent.click(screen.getByRole('button', { name: 'Use my location' }));

    expect(await screen.findByText(/No cinemas found within 10 km/)).toBeInTheDocument();
    expect(api.get).toHaveBeenCalledExactlyOnceWith('/v1/cinemas/nearby', {
        params: { lat: 33.8938, lng: 35.5018, radius: 10000 },
        signal: expect.any(AbortSignal),
    });
});

it('shows an error when the cinema request fails', async () => {
    api.get.mockResolvedValueOnce({ data: { data: coordinates } })
        .mockRejectedValueOnce(new Error('Offline'));

    openPage();
    searchCity('Beirut, Lebanon');

    expect(await screen.findByRole('alert')).toHaveTextContent('Could not load nearby cinemas');
    expect(screen.queryByText(/No cinemas found/)).not.toBeInTheDocument();
});

it('redirects signed-out visitors to login before searching', async () => {
    openPage(false);

    expect(await screen.findByRole('heading', { name: 'Welcome back' })).toBeInTheDocument();
    expect(api.get).not.toHaveBeenCalled();
});