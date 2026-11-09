// Contract-shaped sample response for AB#54; never used as live movie data.
export const movieDetailsResponse = {
  data: {
    tmdb_id: 157336,
    title: 'Interstellar',
    overview: 'A team of explorers travels beyond Earth in search of a new home for humanity.',
    release_date: '2014-11-05',
    runtime_minutes: 169,
    genres: [
      { tmdb_id: 878, name: 'Science Fiction' },
      { tmdb_id: 12, name: 'Adventure' },
    ],
    poster_url: 'https://image.tmdb.org/t/p/w342/gEU2QniE6E77NI6lCU6MxlNBvIx.jpg',
    backdrop_url: 'https://image.tmdb.org/t/p/w780/xJHokMbljvjADYdit5fK5VQsXEG.jpg',
    vote_average: 8.4,
    cast: [
      {
        tmdb_person_id: 10297,
        name: 'Matthew McConaughey',
        character: 'Cooper',
        profile_url: null,
      },
      {
        tmdb_person_id: 1813,
        name: 'Anne Hathaway',
        character: 'Brand',
        profile_url: null,
      },
    ],
    director: 'Christopher Nolan',
    trailer: { site: 'YouTube', key: 'zSWdZVtXT7E' },
  },
};
