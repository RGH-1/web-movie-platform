import { afterEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import { MemoryRouter, Route, Routes } from "react-router-dom";

import MovieDiscovery from "../../src/pages/MovieDiscovery";
import MovieDetailNavigation from "../../src/pages/MovieDetailNavigation";
import { searchMovies, getMovieDetails } from "../../src/services/movies";
import { movieDetailsResponse } from "../fixtures/movieDetails";

vi.mock("../../src/services/movies", () => ({
    searchMovies: vi.fn(),
    getMovieDetails: vi.fn(),
}));

afterEach(() => {
    cleanup();
    sessionStorage.clear();
    vi.resetAllMocks();
});

describe("Movie details complete navigation flow (AB#56)", () => {
    it("opens the correct movie details after clicking a search result", async () => {
        // 1. Prepare the fake search response.
        searchMovies.mockResolvedValue({
            data: [movieDetailsResponse.data],
            meta: {
                page: 1,
                total_pages: 1,
                total_results: 1,
            },
        });

        // 2. Prepare the fake movie-details response.
        getMovieDetails.mockResolvedValue(movieDetailsResponse.data);

        // 3. Start on the Movie Discovery page.
        render(
            <MemoryRouter initialEntries={["/movies"]}>
                <Routes>
                    <Route path="/movies" element={<MovieDiscovery />} />
                    <Route
                        path="/movies/:tmdbId"
                        element={<MovieDetailNavigation />}
                    />
                </Routes>
            </MemoryRouter>,
        );

        // 4. Search for Interstellar.
        fireEvent.change(
            screen.getByRole("searchbox", { name: "Movie title" }),
            { target: { value: "Interstellar" } },
        );

        fireEvent.click(screen.getByRole("button", { name: "Search" }));

        // 5. Verify the search request.
        expect(searchMovies).toHaveBeenCalledWith("Interstellar", 1);

        // 6. Find the movie card's details link.
        const detailsLink = await screen.findByRole("link", {
            name: "View details for Interstellar",
        });

        expect(detailsLink).toHaveAttribute("href", "/movies/157336");

        // 7. Click View Details.
        fireEvent.click(detailsLink);

        // 8. Verify the correct movie details appear.
        expect(
            await screen.findByRole("heading", {
                name: "Interstellar",
                level: 1,
            }),
        ).toBeInTheDocument();

        // 9. Verify the correct ID was requested.
        expect(getMovieDetails).toHaveBeenCalledWith(157336);
        expect(getMovieDetails).toHaveBeenCalledTimes(1);
    });
});
