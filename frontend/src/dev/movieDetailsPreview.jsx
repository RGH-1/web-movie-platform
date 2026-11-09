import { createRoot } from 'react-dom/client';
import 'bootstrap/dist/css/bootstrap.min.css';
import '../index.css';
import MovieDetailsPage from '../pages/MovieDetailsPage';
import { movieDetailsResponse } from '../../tests/fixtures/movieDetails';

// shortcut: isolated sample-data preview; replace with the live route in AB#55.
const movie = new URLSearchParams(window.location.search).has('missing')
  ? { tmdb_id: 1, title: 'Movie with missing metadata' }
  : movieDetailsResponse.data;

createRoot(document.getElementById('root')).render(
  <>
    <div className="alert alert-info m-3" role="status">
      Layout preview with sample data. This is not a live movie-details request.
      <a href="/movie-details-preview.html" className="ms-2">Full sample</a>
      <a href="/movie-details-preview.html?missing" className="ms-2">Missing metadata</a>
    </div>
    <MovieDetailsPage movie={movie} />
  </>,
);
