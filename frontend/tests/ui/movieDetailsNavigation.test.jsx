import { afterEach, describe, expect, it, vi } from "vitest";
import { cleanup, render, screen } from "@testing-library/react";
import { MemoryRouter, Route, Routes } from "react-router-dom";

import MovieDetailNavigation from "../../src/pages/MovieDetailNavigation";
import { getMovieDetails } from "../../src/services/movies";
import { movieDetailsResponse } from "../fixtures/movieDetails";

vi.mock("../../src/services/movies", () => ({
    getMovieDetails: vi.fn(),
}));

function renderMovieDetails(url) {
    return render(
        <MemoryRouter initialEntries={[url]}>
            <Routes>
                <Route
                    path="/movies/:tmdbId"
                    element={<MovieDetailNavigation />}
                />
            </Routes>
        </MemoryRouter>,
    );
}

afterEach(() => {
    cleanup();
    vi.resetAllMocks();
});

describe("Movie details navigation (AB#56)", () => {
    it("loads the correct movie when opening a valid URL", async () => {
        getMovieDetails.mockResolvedValue(movieDetailsResponse.data);

        renderMovieDetails("/movies/157336");

        expect(
            await screen.findByRole("heading", { name: "Interstellar" }),
        ).toBeInTheDocument();

        expect(getMovieDetails).toHaveBeenCalledWith(157336);
        expect(getMovieDetails).toHaveBeenCalledTimes(1);
    });

    it("shows loading while the API request is pending", async () => {
        let finishRequest;

        const pendingRequest = new Promise((resolve) => {
            finishRequest = resolve;
        });

        getMovieDetails.mockReturnValue(pendingRequest);

        renderMovieDetails("/movies/157336");

        expect(screen.getByRole("status")).toHaveTextContent(
            "Loading movie details...",
        );

        finishRequest(movieDetailsResponse.data);

        expect(
            await screen.findByRole("heading", { name: "Interstellar" }),
        ).toBeInTheDocument();
    });

    it("rejects invalid movie IDs without calling the API", () => {
        renderMovieDetails("/movies/abc");

        expect(screen.getByRole("alert")).toHaveTextContent(
            "Invalid movie identifier.",
        );

        expect(getMovieDetails).not.toHaveBeenCalled();
    });

    it("shows Movie not found when the backend returns 404", async () => {
        getMovieDetails.mockRejectedValue({
            response: { status: 404 },
        });

        renderMovieDetails("/movies/999999999");

        expect(await screen.findByRole("alert")).toHaveTextContent(
            "Movie not found.",
        );

        expect(getMovieDetails).toHaveBeenCalledWith(999999999);
    });

    it("rejects out-of-range movie IDs without calling the API", () => {
        renderMovieDetails("/movies/2147483648");

        expect(screen.getByRole("alert")).toHaveTextContent(
            "Invalid movie identifier.",
        );

        expect(getMovieDetails).not.toHaveBeenCalled();
    });

    it("shows a profile completion message for HTTP 403", async () => {
        getMovieDetails.mockRejectedValue({
            response: { status: 403 },
        });

        renderMovieDetails("/movies/157336");

        expect(await screen.findByRole("alert")).toHaveTextContent(
            "Please complete your profile to view movie details.",
        );
    });

    it.each([502, 503])(
        "shows a temporary failure message for HTTP %i",
        async (status) => {
            getMovieDetails.mockRejectedValue({
                response: { status },
            });

            renderMovieDetails("/movies/157336");

            expect(await screen.findByRole("alert")).toHaveTextContent(
                "Movie information is temporarily unavailable.",
            );
        },
    );

    it("handles network failures without crashing", async () => {
        getMovieDetails.mockRejectedValue(new Error("Network Error"));

        renderMovieDetails("/movies/157336");

        expect(await screen.findByRole("alert")).toHaveTextContent(
            "Unable to load movie details. Please try again.",
        );
    });
});
