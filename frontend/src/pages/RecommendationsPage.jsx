import { useEffect, useState } from 'react';
import { Link, Navigate, useLocation } from 'react-router-dom';
import MovieCard from '../components/MovieCard';
import ProfileCompletionNotice from '../components/ProfileCompletionNotice';
import { getRecommendations, resolveRecommendationCity } from '../services/recommendations';
import { completionFromError } from '../utils/profileCompletion';

export default function RecommendationsPage() {
    const location = useLocation();
    const [city, setCity] = useState('');
    const [selection, setSelection] = useState({});
    const [locating, setLocating] = useState(false);
    const [locationError, setLocationError] = useState('');
    const [result, setResult] = useState(null);

    useEffect(() => {
        const controller = new AbortController();
        const { signal } = controller;

        async function load() {
            try {
                let coordinates = selection.coordinates;
                if (selection.city) {
                    try {
                        coordinates = await resolveRecommendationCity(selection.city, { signal });
                    } catch (error) {
                        if ([401, 403].includes(error.response?.status)) throw error;
                        coordinates = null;
                    }
                }
                if (signal.aborted) return;

                const response = await getRecommendations(coordinates, { signal });
                if (!signal.aborted) setResult({ selection, response, cityUnavailable: selection.city && !coordinates });
            } catch (error) {
                if (!signal.aborted) setResult({ selection, error });
            }
        }

        load();
        return () => controller.abort();
    }, [selection]);

    function setLocation(nextSelection) {
        setLocationError('');
        setSelection(nextSelection);
    }

    function submitCity(event) {
        event.preventDefault();
        const name = city.trim();
        if (!name) {
            setLocationError('Enter a city name.');
            return;
        }
        setLocation({ city: name });
    }

    function useDeviceLocation() {
        if (!navigator.geolocation) {
            setLocationError('Device location is unavailable. You can enter a city instead.');
            return;
        }
        setLocationError('');
        setLocating(true);
        navigator.geolocation.getCurrentPosition(({ coords }) => {
            setLocating(false);
            setLocation({ coordinates: {
                latitude: Number(coords.latitude.toFixed(2)),
                longitude: Number(coords.longitude.toFixed(2)),
            } });
        }, () => {
            setLocating(false);
            setLocationError('Could not use your device location. You can enter a city or browse without location.');
        }, { timeout: 8000 });
    }

    const current = result?.selection === selection ? result : null;
    if (current?.error?.response?.status === 401) {
        return <Navigate to="/login" replace state={{ from: location }} />;
    }

    const completion = current?.error ? completionFromError(current.error) : null;
    const response = current?.response;
    const weather = response?.meta.weather;
    const place = selection.city || 'your approximate device location';
    const retry = () => setSelection({ ...selection });

    return (
        <main className="container py-4 py-md-5 text-start">
            <Link to="/">Back to home</Link>
            <h1 className="h2 mt-3">Recommendations</h1>

            {completion ? <ProfileCompletionNotice completion={completion} /> : (
                <>
                    <fieldset className="border rounded p-3 mb-4" disabled={locating}>
                        <legend className="h5 float-none w-auto px-1">Location for weather</legend>
                        <div className="d-flex flex-wrap gap-2 mb-3">
                            <button type="button" className="btn btn-outline-primary" onClick={useDeviceLocation}>
                                Use my location
                            </button>
                            {(selection.coordinates || selection.city) && (
                                <button type="button" className="btn btn-outline-secondary" onClick={() => setLocation({})}>
                                    Browse without location
                                </button>
                            )}
                        </div>
                        <form onSubmit={submitCity}>
                            <label className="form-label" htmlFor="recommendation-city">City</label>
                            <div className="d-flex flex-wrap gap-2">
                                <input id="recommendation-city" className="form-control w-auto mw-100" value={city}
                                    onChange={(event) => setCity(event.target.value)} placeholder="Beirut, Lebanon"
                                    maxLength={120} required />
                                <button type="submit" className="btn btn-outline-primary">Use city</button>
                            </div>
                        </form>
                    </fieldset>
                    {locating && <p role="status">Waiting for device location…</p>}
                    {locationError && <p className="text-body-secondary" role="status">{locationError}</p>}
                    {!current && <p role="status">Loading recommendations…</p>}
                    {current?.error && (
                        <div className="alert alert-danger" role="alert">
                            <p>{current.error.response?.status === 429
                                ? 'Too many requests. Wait a minute and try again.'
                                : 'Could not load recommendations. Please try again.'}</p>
                            <button type="button" className="btn btn-outline-danger" disabled={locating} onClick={retry}>
                                Try again
                            </button>
                        </div>
                    )}
                    {response && (
                        <>
                            {current.cityUnavailable ? (
                                <div className="alert alert-warning" role="status">
                                    <p>Could not confirm that city. Try including the country or region. Showing recommendations without weather.</p>
                                    <button type="button" className="btn btn-sm btn-outline-dark"
                                        disabled={locating} onClick={retry}>Retry city lookup</button>
                                </div>
                            ) : weather.status === 'available' ? (
                                <p className="text-break">
                                    <strong>Weather for {place}</strong>{' · '}
                                    <span className="text-capitalize">{weather.condition}</span>{' · '}
                                    {weather.temperature_c} °C <span className="small">(<a href="https://open-meteo.com/">Open-Meteo</a>)</span>
                                </p>
                            ) : (
                                <p className="text-body-secondary">{weather.status === 'unavailable'
                                    ? `Weather is unavailable for ${place}. Showing recommendations without weather.`
                                    : 'Showing recommendations without location.'}</p>
                            )}

                            <h2 className="h4">{response.meta.personalization === 'personalized'
                                ? 'Picked for you' : 'General recommendations'}</h2>
                            {response.data.length > 0 && response.meta.warnings.some((warning) => warning !== 'WEATHER_UNAVAILABLE') && (
                                <p className="text-body-secondary">Some recommendation sources are temporarily unavailable.</p>
                            )}
                            {response.data.length === 0 ? (
                                <div className="alert alert-info" role="status">
                                    <p className="mb-0">{response.meta.empty_reason === 'sources_unavailable'
                                        ? 'Recommendation sources are temporarily unavailable.' : 'No eligible movies to recommend yet.'}</p>
                                    {response.meta.empty_reason === 'sources_unavailable' && (
                                        <button type="button" className="btn btn-sm btn-outline-dark mt-2"
                                            disabled={locating} onClick={retry}>Try again</button>
                                    )}
                                </div>
                            ) : (
                                <div className="row g-4">
                                    {response.data.map((movie) => (
                                        <div className="col-12 col-sm-6 col-lg-4" key={movie.local_movie_id ?? `tmdb-${movie.tmdb_id}`}>
                                            <MovieCard movie={movie}>
                                                {movie.recommendation.reasons.length > 0 && (
                                                    <div className="small">
                                                        <h3 className="h6">Why this movie</h3>
                                                        <ul className="ps-3 mb-2">
                                                            {movie.recommendation.reasons.map(({ code, message }) => (
                                                                <li key={code}>{message}</li>
                                                            ))}
                                                        </ul>
                                                    </div>
                                                )}
                                            </MovieCard>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </>
                    )}
                </>
            )}
        </main>
    );
}
