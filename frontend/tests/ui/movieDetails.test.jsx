import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import MovieDetailsPage from "../../src/pages/MovieDetailsPage";
import { movieDetailsResponse } from "../fixtures/movieDetails";

describe("movie details layout (AB#54)", () => {
  it("renders the agreed movie fields and separate cast/trailer sections", () => {
    render(<MovieDetailsPage movie={movieDetailsResponse.data} />);
    expect(screen.getByRole("heading", { level: 1 })).toHaveTextContent(
      "Interstellar",
    );
    for (const text of [
      "2014-11-05",
      "169 minutes",
      "8.4 / 10",
      "Christopher Nolan",
      "Science Fiction",
      "Adventure",
      "Matthew McConaughey",
      "Cooper",
      "Anne Hathaway",
      "Brand",
    ]) {
      expect(screen.getByText(text)).toBeInTheDocument();
    }
    expect(
      screen.getByText(movieDetailsResponse.data.overview),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("region", { name: "Principal cast" }),
    ).toBeInTheDocument();
    expect(screen.getByRole("region", { name: "Trailer" })).toBeInTheDocument();
    expect(
      screen.getByRole("img", { name: "Interstellar poster" }),
    ).toHaveAttribute("src", movieDetailsResponse.data.poster_url);
  });

  it("renders missing optional metadata without crashing or showing broken images", () => {
    render(
      <MovieDetailsPage
        movie={{ tmdb_id: 1, title: "Minimal movie", trailer: null }}
      />,
    );
    expect(screen.getByText("No poster available")).toBeInTheDocument();
    expect(screen.getByText("No backdrop available")).toBeInTheDocument();
    expect(screen.getByText("No synopsis available.")).toBeInTheDocument();
    expect(screen.getByText("No genres available.")).toBeInTheDocument();
    expect(
      screen.getByText("No cast information available."),
    ).toBeInTheDocument();
    expect(screen.getByText("No trailer available.")).toBeInTheDocument();
    expect(screen.getAllByText("Not available")).toHaveLength(4);
    expect(screen.queryByRole("img")).not.toBeInTheDocument();
  });

  it("replaces failed poster, backdrop and cast photos with placeholders", () => {
    const movie = {
      ...movieDetailsResponse.data,
      cast: [
        {
          ...movieDetailsResponse.data.cast[0],
          profile_url: "https://example.com/missing.jpg",
        },
      ],
    };
    render(<MovieDetailsPage movie={movie} />);
    fireEvent.error(screen.getByRole("img", { name: "Interstellar poster" }));
    fireEvent.error(screen.getByRole("img", { name: "Interstellar backdrop" }));
    fireEvent.error(screen.getByRole("img", { name: "Matthew McConaughey" }));
    expect(screen.getByText("No poster available")).toBeInTheDocument();
    expect(screen.getByText("No backdrop available")).toBeInTheDocument();
    expect(screen.getByText("No cast photo available")).toBeInTheDocument();
  });

  it("renders a zero rating, optional cast role and synopsis as plain text", () => {
    const { container } = render(
      <MovieDetailsPage
        movie={{
          ...movieDetailsResponse.data,
          vote_average: 0,
          overview: "<script>not HTML</script>",
          cast: [{ ...movieDetailsResponse.data.cast[0], character: null }],
        }}
      />,
    );
    expect(screen.getByText("0 / 10")).toBeInTheDocument();
    expect(screen.getByText("Role not available")).toBeInTheDocument();
    expect(screen.getByText("<script>not HTML</script>")).toBeInTheDocument();
    expect(container.querySelector("script")).toBeNull();
  });

  it("can display a new image after an earlier movie image failed", () => {
    const { rerender } = render(
      <MovieDetailsPage movie={movieDetailsResponse.data} />,
    );
    fireEvent.error(screen.getByRole("img", { name: "Interstellar poster" }));
    rerender(
      <MovieDetailsPage
        movie={{
          ...movieDetailsResponse.data,
          title: "Another movie",
          poster_url: "https://example.com/another.jpg",
        }}
      />,
    );
    expect(
      screen.getByRole("img", { name: "Another movie poster" }),
    ).toHaveAttribute("src", "https://example.com/another.jpg");
  });

  it("opens a valid YouTube trailer in a new tab", () => {
    render(<MovieDetailsPage movie={movieDetailsResponse.data} />);

    const trailerLink = screen.getByRole("link", {
      name: "Watch Trailer",
    });

    expect(trailerLink).toHaveAttribute(
      "href",
      "https://www.youtube.com/watch?v=zSWdZVtXT7E",
    );

    expect(trailerLink).toHaveAttribute("target", "_blank");

    expect(trailerLink).toHaveAttribute("rel", "noopener noreferrer");
  });

  it("rejects invalid and unsupported trailer information", () => {
    const { rerender } = render(
      <MovieDetailsPage
        movie={{
          ...movieDetailsResponse.data,
          trailer: {
            site: "YouTube",
            key: "invalid/key",
          },
        }}
      />,
    );

    expect(screen.getByText("No trailer available.")).toBeInTheDocument();

    expect(
      screen.queryByRole("link", { name: "Watch Trailer" }),
    ).not.toBeInTheDocument();

    rerender(
      <MovieDetailsPage
        movie={{
          ...movieDetailsResponse.data,
          trailer: {
            site: "Other",
            key: "zSWdZVtXT7E",
          },
        }}
      />,
    );

    expect(screen.getByText("No trailer available.")).toBeInTheDocument();
  });
});
