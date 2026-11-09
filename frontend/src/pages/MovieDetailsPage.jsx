import { useState } from "react";

function MovieImage({ src, alt, placeholder, aspectRatio }) {
  const [failed, setFailed] = useState(false);

  return (
    <div
      className="rounded overflow-hidden bg-body-tertiary"
      style={{ aspectRatio }}
    >
      {src && !failed ? (
        <img
          src={src}
          alt={alt}
          className="w-100 h-100 object-fit-cover"
          loading="lazy"
          onError={() => setFailed(true)}
        />
      ) : (
        <div className="h-100 d-flex align-items-center justify-content-center p-3 text-center text-body-secondary">
          {placeholder}
        </div>
      )}
    </div>
  );
}

export default function MovieDetailsPage({ movie }) {
  const title = movie.title?.trim() || "Untitled movie";
  const cast = movie.cast ?? [];
  const genres = movie.genres ?? [];
  const trailerKey =
    movie.trailer?.site === "YouTube" &&
    typeof movie.trailer.key === "string" &&
    /^[A-Za-z0-9_-]{11}$/.test(movie.trailer.key)
      ? movie.trailer.key
      : null;

  return (
    <main className="container py-4 py-md-5 text-start">
      <MovieImage
        key={movie.backdrop_url}
        src={movie.backdrop_url}
        alt={title + " backdrop"}
        placeholder="No backdrop available"
        aspectRatio="16 / 9"
      />

      <div className="row g-4 mt-1 mb-5">
        <div className="col-8 col-sm-5 col-md-4 col-lg-3">
          <MovieImage
            key={movie.poster_url}
            src={movie.poster_url}
            alt={title + " poster"}
            placeholder="No poster available"
            aspectRatio="2 / 3"
          />
        </div>
        <div className="col-12 col-md-8 col-lg-9">
          <h1 className="fs-2 mt-0 mb-3 text-break">{title}</h1>
          <dl className="row mb-3">
            <dt className="col-5 col-sm-4">Release date</dt>
            <dd className="col-7 col-sm-8">
              {movie.release_date || "Not available"}
            </dd>
            <dt className="col-5 col-sm-4">Runtime</dt>
            <dd className="col-7 col-sm-8">
              {movie.runtime_minutes > 0
                ? movie.runtime_minutes + " minutes"
                : "Not available"}
            </dd>
            <dt className="col-5 col-sm-4">TMDB rating</dt>
            <dd className="col-7 col-sm-8">
              {movie.vote_average != null
                ? movie.vote_average + " / 10"
                : "Not available"}
            </dd>
            <dt className="col-5 col-sm-4">Director</dt>
            <dd className="col-7 col-sm-8 text-break">
              {movie.director || "Not available"}
            </dd>
          </dl>

          <h2 className="h5 mb-2">Genres</h2>
          {genres.length ? (
            <ul className="list-unstyled d-flex flex-wrap gap-2 mb-4">
              {genres.map((genre) => (
                <li
                  key={genre.tmdb_id}
                  className="badge text-bg-secondary text-wrap"
                >
                  {genre.name}
                </li>
              ))}
            </ul>
          ) : (
            <p className="text-body-secondary mb-4">No genres available.</p>
          )}

          <h2 className="h5 mb-2">Synopsis</h2>
          <p className="text-break" style={{ whiteSpace: "pre-wrap" }}>
            {movie.overview?.trim() || "No synopsis available."}
          </p>
        </div>
      </div>

      <section className="mb-5" aria-labelledby="cast-heading">
        <h2 id="cast-heading" className="h4 mb-3">
          Principal cast
        </h2>
        {cast.length ? (
          <ul className="row g-3 list-unstyled">
            {cast.map((person) => (
              <li
                key={person.tmdb_person_id}
                className="col-6 col-sm-4 col-md-3"
              >
                <article className="card h-100">
                  <MovieImage
                    key={person.profile_url}
                    src={person.profile_url}
                    alt={person.name}
                    placeholder="No cast photo available"
                    aspectRatio="2 / 3"
                  />
                  <div className="card-body">
                    <h3 className="h6 mb-1 text-break">{person.name}</h3>
                    <p className="small text-body-secondary text-break">
                      {person.character || "Role not available"}
                    </p>
                  </div>
                </article>
              </li>
            ))}
          </ul>
        ) : (
          <p className="text-body-secondary">No cast information available.</p>
        )}
      </section>

      <section className="card" aria-labelledby="trailer-heading">
        <div className="card-body p-3 p-md-4">
          <h2 id="trailer-heading" className="h4 mb-3">
            Trailer
          </h2>

          {trailerKey ? (
            <a
              href={`https://www.youtube.com/watch?v=${trailerKey}`}
              target="_blank"
              rel="noopener noreferrer"
              className="btn btn-primary"
            >
              Watch Trailer
            </a>
          ) : (
            <p className="text-body-secondary">No trailer available.</p>
          )}
        </div>
      </section>
    </main>
  );
}
