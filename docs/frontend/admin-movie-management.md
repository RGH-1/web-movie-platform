# Administrator movie management (AB#155)

Sign in as an administrator and choose **Movie management** from the homepage.
The catalog includes local and TMDB-linked movies. Search by title, choose a
source or status, then click **Apply filters**. Use **Next**, **Previous**, or
the page number to browse paginated results.

**Create local movie** requires only a title. The rest of the metadata is
optional. Poster, backdrop, and cast profile links must use HTTPS. For a
trailer, enter the YouTube video ID rather than the whole URL. Genre and
cast rows accept names and optional TMDB IDs.

**Edit** loads the complete record. Saving sends changed fields only.
For TMDB-linked titles, those fields become catalog overrides. Clearing a
nullable field sends null. Removing all genre or cast rows sends an empty
array. The source and movie IDs are set by Laravel.

**Deactivate** asks for confirmation. The record and its history remain
in the admin catalog. Active records link to movie details using their
canonical local ID.

## Browser verification

1. From `http://localhost:5173/`, open **Movie management** as an administrator.
   Search for a known title and apply the **TMDB** filter. Check the source
   badge and provider ID. Use pagination if there are more than 20 matches.
2. Select **Create local movie**. Try saving with a blank title and check the
   field error. Then enter **Catalog test movie**, add an optional release date
   and genre, and save. Clear any filters that would hide the new movie.
3. Find the new **Local** card. Check its missing-poster fallback and click
   **View details**. Return to the catalog and edit the overview.
4. Edit a TMDB-linked test record, changing just one approved field. In the
   Network panel, inspect the PATCH JSON body and confirm it contains that
   field. Check the record still shows the same TMDB ID.
5. On the local test movie, select **Deactivate**, then **Cancel**. Repeat and
   confirm. Under the **Deactivated** filter the record should remain, with
   its public details link removed.
6. In a separate regular-user session, open `/admin/movies` directly and
   verify that administrator access is required. Check the admin page at
   narrow and wide viewport widths.

The service uses the existing Sanctum session cookie and requests a CSRF
cookie before writes. See the [admin catalog API contract](../api/admin-movie-catalog-contract.md)
for exact request fields and response examples.
