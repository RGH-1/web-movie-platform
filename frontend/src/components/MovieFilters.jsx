import { useEffect, useRef, useState } from 'react';
import MovieCard from './MovieCard';
import MoviePagination from './MoviePagination';
import { discoverMovies, getMovieGenres } from '../services/movies';

const DEFAULT_FILTERS = { genre_id: '', year: '', min_rating: '', sort_by: 'popularity_desc' };
const SORTS = [
    ['popularity_desc', 'Most popular'], ['popularity_asc', 'Least popular'],
    ['rating_desc', 'Highest rated'], ['rating_asc', 'Lowest rated'],
    ['release_date_desc', 'Newest releases'], ['release_date_asc', 'Oldest releases'],
];

export default function MovieFilters() {
    const [maximumYear] = useState(() => new Date().getFullYear() + 5);
    const [filters, setFilters] = useState(DEFAULT_FILTERS);
    const [selection, setSelection] = useState({ filters: DEFAULT_FILTERS, page: 1 });
    const [genres, setGenres] = useState([]);
    const [genreError, setGenreError] = useState('');
    const [genreAttempt, setGenreAttempt] = useState(0);
    const [result, setResult] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [errors, setErrors] = useState({});
    const sequence = useRef(0);

    useEffect(() => {
        let active = true;
        getMovieGenres().then(data => {
            if (active) setGenres(data);
        }).catch(() => {
            if (active) setGenreError('Genres are temporarily unavailable. You can still use the other filters.');
        });
        return () => { active = false; };
    }, [genreAttempt]);

    useEffect(() => {
        const request = ++sequence.current;
        let active = true;
        discoverMovies(selection.filters, selection.page).then(data => {
            if (active && request === sequence.current) setResult(data);
        }).catch(err => {
            if (!active || request !== sequence.current) return;
            if (err.response?.status === 422) {
                setErrors(err.response.data.errors ?? {});
                setError('Please check the selected filters.');
            } else {
                setError(err.response?.status === 503
                    ? 'Movie discovery is busy. Please try again shortly.'
                    : 'Movie discovery is temporarily unavailable. Please try again.');
            }
        }).finally(() => {
            if (active && request === sequence.current) setLoading(false);
        });
        return () => { active = false; };
    }, [selection]);

    function applySelection(next) {
        setLoading(true);
        setError('');
        setErrors({});
        setSelection(next);
    }

    function update(event) {
        setFilters(current => ({ ...current, [event.target.name]: event.target.value }));
    }

    function submit(event) {
        event.preventDefault();
        const invalid = {};
        if (event.currentTarget.elements.year.validity.badInput || (filters.year !== '' && (!/^\d{4}$/.test(filters.year) || Number(filters.year) < 1874 || Number(filters.year) > maximumYear))) {
            invalid.year = [`Enter a four-digit year from 1874 to ${maximumYear}, or leave it blank.`];
        }
        if (event.currentTarget.elements.min_rating.validity.badInput || (filters.min_rating !== '' && (!Number.isFinite(Number(filters.min_rating)) || Number(filters.min_rating) < 0 || Number(filters.min_rating) > 10))) {
            invalid.min_rating = ['Enter a TMDB rating from 0 to 10, or leave it blank.'];
        }
        if (Object.keys(invalid).length > 0) {
            setErrors(invalid);
            return;
        }
        applySelection({ filters: { ...filters }, page: 1 });
    }

    function fieldError(name) {
        return errors[name] ? <div id={`${name}-error`} className="text-danger small" role="alert">{errors[name][0]}</div> : null;
    }

    return (
        <section aria-label="Browse movies by filters">
            <form className="row g-3 mb-4" onSubmit={submit} noValidate>
                <div className="col-12 col-md-3">
                    <label htmlFor="genre_id" className="form-label">Genre</label>
                    <select id="genre_id" name="genre_id" className="form-select" value={filters.genre_id} onChange={update} disabled={loading || genres.length === 0} aria-invalid={!!errors.genre_id} aria-describedby={errors.genre_id ? 'genre_id-error' : undefined}>
                        <option value="">All genres</option>
                        {genres.map(genre => <option key={genre.id} value={genre.id}>{genre.name}</option>)}
                    </select>
                    {fieldError('genre_id')}
                </div>
                <div className="col-6 col-md-2">
                    <label htmlFor="year" className="form-label">Release year</label>
                    <input id="year" name="year" className="form-control" type="number" min="1874" max={maximumYear} step="1" placeholder="Any year" value={filters.year} onChange={update} disabled={loading} aria-invalid={!!errors.year} aria-describedby={errors.year ? 'year-error' : undefined} />
                    {fieldError('year')}
                </div>
                <div className="col-6 col-md-2">
                    <label htmlFor="min_rating" className="form-label">Minimum rating</label>
                    <input id="min_rating" name="min_rating" className="form-control" type="number" min="0" max="10" step="any" list="tmdb-rating-options" placeholder="Any rating" value={filters.min_rating} onChange={update} disabled={loading} aria-invalid={!!errors.min_rating} aria-describedby={`tmdb-rating-help${errors.min_rating ? ' min_rating-error' : ''}`} />
                    <datalist id="tmdb-rating-options">
                        {[5, 6, 7, 8, 9].map((rating) => <option key={rating} value={rating}>{rating}+</option>)}
                    </datalist>
                    <p id="tmdb-rating-help" className="form-text">TMDB rating out of 10. Platform user ratings use 1–5.</p>
                    {fieldError('min_rating')}
                </div>
                <div className="col-12 col-md-3">
                    <label htmlFor="sort_by" className="form-label">Sort by</label>
                    <select id="sort_by" name="sort_by" className="form-select" value={filters.sort_by} onChange={update} disabled={loading} aria-invalid={!!errors.sort_by} aria-describedby={errors.sort_by ? 'sort_by-error' : undefined}>
                        {SORTS.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                    </select>
                    {fieldError('sort_by')}
                </div>
                <div className="col-12 col-md-2 d-flex align-items-end gap-2">
                    <button className="btn btn-primary" disabled={loading}>Apply filters</button>
                    <button type="button" className="btn btn-outline-secondary" disabled={loading} onClick={() => {
                        setFilters(DEFAULT_FILTERS);
                        applySelection({ filters: DEFAULT_FILTERS, page: 1 });
                    }}>Clear filters</button>
                </div>
            </form>
            {genreError && <div className="alert alert-warning" role="alert">{genreError} <button className="btn btn-link" onClick={() => { setGenreError(''); setGenreAttempt(value => value + 1); }}>Retry genres</button></div>}
            <div className="mb-3" aria-label="Applied filters">
                <h2 className="h6">Applied filters</h2>
                <ul className="list-unstyled d-flex flex-wrap gap-2 mb-0">
                    <li className="badge text-bg-secondary">Genre: {selection.filters.genre_id
                        ? genres.find((genre) => String(genre.id) === selection.filters.genre_id)?.name ?? 'Selected genre'
                        : 'All genres'}</li>
                    <li className="badge text-bg-secondary">Year: {selection.filters.year || 'Any year'}</li>
                    <li className="badge text-bg-secondary">TMDB rating: {selection.filters.min_rating === '' ? 'Any rating' : `${selection.filters.min_rating}+ / 10`}</li>
                    <li className="badge text-bg-secondary">Sort: {SORTS.find(([value]) => value === selection.filters.sort_by)?.[1]}</li>
                </ul>
            </div>
            {loading && <p role="status">Loading movies...</p>}
            {error && <div className="alert alert-danger" role="alert">{error} <button className="btn btn-link" onClick={() => applySelection(current => ({ ...current }))}>Retry discovery</button></div>}
            {!loading && !error && result && <>
                <p className="text-body-secondary">{result.meta.total_results} movie(s) found</p>
                {result.data.length === 0
                    ? <div className="alert alert-info" role="status">No movies found on this page. Try other filters or a previous page.</div>
                    : <div className="row g-4">{result.data.map(movie => <div key={movie.tmdb_id} className="col-12 col-sm-6 col-md-4 col-lg-3"><MovieCard movie={movie} /></div>)}</div>}
                {result.meta.total_pages > 1 && <MoviePagination
                    page={result.meta.page} totalPages={result.meta.total_pages}
                    onPageChange={(page) => applySelection(current => ({ ...current, page }))}
                    label="Filtered movie results pagination" />}
            </>}
        </section>
    );
}
