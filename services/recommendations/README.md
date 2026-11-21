# Recommendations — AB#112

Recommendations are designed as part of the existing Laravel backend.

The implementation uses Laravel service classes, the canonical movie
catalog, visible ratings, the requesting user's preferences and watched
history, TMDB metadata, and optional current weather.

Weather contributes to the final weighted ranking.

Design documents:

- [Algorithm](../../docs/architecture/recommendation-algorithm.md)
- [API contract](../../docs/api/recommendations-contract.md)
- [Acceptance cases](../../docs/testing/recommendation-design-cases.md)

These documents specify the intended implementation. This directory does
not currently contain a running recommendation service.
Recommendation Service would go in [Services](../../backend/app/Services/)