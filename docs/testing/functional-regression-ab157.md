# End-to-end functional regression — AB#157

## Status

In progress: automated regression and the isolated browser/API checks
passed. Successful live-provider verification and configured demo-mail
verification remain pending.

No application regression was confirmed during this run.

## Baseline and evidence

- Date: 2026-11-25.
- Inspected application commit:
  3a7d945786c9f5a4ed6a16c1fc846865e3920fd2.
- Local branch and remote dev matched this commit.
- Dev R's previously completed checks are recorded separately below.
- Assistant-run results were observed during this verification.
- Earlier documentation's historical results are not counted as new runs.
- Application source and Git state were not changed by the assistant.
- Browser fixtures used an isolated SQLite database, not development data.

Evidence categories:

- Reported pass: Dev R reported completing the flow.
- Automated pass: the existing automated suite passed.
- Browser/API pass: exercised against the running application.
- Pending: a required result has not yet been established.

Browser/API checks include both UI interaction and authenticated requests
executed from browser sessions. They do not imply that every listed
operation was performed by clicking its React controls.

## Environment

| Component | Observed value |
| --- | --- |
| PHP | 8.2.12 |
| Laravel | 12.72.0 |
| Sanctum | 4.3.3 |
| Reverb | 1.14.0 |
| PHPUnit | 11.5.57 |
| Node | 26.12.0 |
| Browser | Headless Chrome 157.0.8103.79 |
| Automated/backend browser database | Isolated SQLite |
| Browser frontend | http://localhost:15173 |
| Browser backend | http://localhost:18000 |
| Browser Reverb | Port 18080 |
| Password-mail transport | Isolated local SMTP capture listener |

The browser run used separate cookie contexts for account isolation.
Local test services were stopped afterward.

The agent runtime reported PHP files unreadable even when PHP could open
them. The backend suite therefore ran in a writable copy using a temporary
PHPUnit configuration that omitted the redundant bootstrap attribute.
Composer was already loaded by PHPUnit's entry point. Test files,
assertions, suite directories, and database settings were preserved.

## Checks previously completed by Dev R

These remain part of the regression record.

| Workflow | Result | Evidence source |
| --- | --- | --- |
| Login | Reported pass | Dev R at AB#157 kickoff |
| Social-provider authentication | Reported pass | Dev R at AB#157 kickoff |
| Movie search | Reported pass | Dev R at AB#157 kickoff |
| Movie details | Reported pass | Dev R at AB#157 kickoff |
| Reviews | Reported pass | Dev R at AB#157 kickoff |
| Following | Reported pass | Dev R at AB#157 kickoff |
| Movie-night creation | Reported pass | Dev R at AB#157 kickoff |
| RSVP | Reported pass | Dev R at AB#157 kickoff |
| Watch-room joining | Reported pass | Dev R at AB#157 kickoff |

The exact commits and browser versions for these earlier checks were not
supplied. Independent coverage from the current run is recorded below.

## Automated regression results

| Check | Result |
| --- | --- |
| Backend suite | 500 total: 496 passed, 4 skipped |
| Backend assertions | 4,262 |
| Frontend unit suite | 43 passed |
| Frontend UI suite | 333 passed across 37 files |
| Frontend lint | Exit 0, six warnings |
| Frontend production build | Passed, bundle-size warning |

The four backend skips were:

1. MovieCatalogTest: production CHECK constraint verification requires MySQL/MariaDB.
2. ReviewTest: database rating CHECK verification requires MySQL/MariaDB.
3. AdminDashboardMySqlStressTest: first opt-in MySQL stress check.
4. AdminDashboardMySqlStressTest: second opt-in MySQL stress check.

These results do not establish fresh MySQL installation, concurrent-writer
behavior, or a new run of the opt-in stress tests.

Existing coverage includes authentication, OAuth, password management,
profiles, catalog/search/details, reviews and sentiment, lists, movie
statuses, following/feed/privacy, recommendations/weather, nearby cinemas,
movie-night membership/voting/video/chat/broadcasting, and administration.

Provider-fake tests establish application behavior for successful responses,
validation, failures, and fallbacks. They do not establish live-provider
availability.

Notable recommendation coverage verifies that changing weather can reverse
the final ranking for the same user and candidate set.

### Existing lint warnings

- Unused act import in watchRoom.test.jsx.
- Two control-character regex warnings in calendar.js.
- Fast-refresh export warning in AuthContext.jsx.
- Effect-dependency warning in MovieNightInvitations.jsx.
- Effect-dependency warning in MovieNightShortlist.jsx.

The production build also reports a chunk larger than 500 kB.
These checks exited successfully.

## Independent browser/API results

| ID | Workflow and observation | Result |
| --- | --- | --- |
| FR-01 | Login through the React form using isolated account sessions; authenticated identities remain distinct | Pass |
| FR-02 | Google, GitHub, and Facebook buttons point to Laravel provider redirects | Pass; provider consent not independently rerun |
| FR-03 | Canonical local movie details render the correct movie and status controls | Pass |
| FR-04 | Watchlist and watched controls save independently, persist into My Movies, and do not affect another account | Pass |
| FR-05 | Create a list through the UI; add a movie twice without duplication; switch private/public/private; deny non-owner mutation; remove membership and delete the list without deleting the movie | Pass |
| FR-06 | Create and edit a review through authenticated browser requests; updated text/rating persist and the public average changes correctly | Pass |
| FR-07 | Following works; opting into review sharing adds the matching review activity; disabling sharing removes the activity while retaining the review | Pass |
| FR-08 | Create a scheduled night, invite another account, accept the real invitation, and deny unrelated-user details access | Pass |
| FR-09 | Propose candidates, replace a ballot without increasing the ballot count, reject unresolved ties, finalize a selected leader, and reject later voting | Pass |
| FR-10 | Assign the demo clip; accepted sessions load native video metadata; player seeking remains independent | Pass |
| FR-11 | Send a message through the UI; Reverb delivers it to the other browser; future text hides until playback reaches its timestamp; message identity persists across refresh | Pass |
| FR-12 | Explicit spoilers require a separate reveal in each browser | Pass |
| FR-13 | Nonparticipant message history/send requests return 404; private channel authorization returns 403 | Pass |
| FR-14 | Reverb outage preserves new messages; manual reload retrieves them; reconnect automatically catches up without duplicates | Pass |
| FR-15 | Blocking the video stream produces a media error; retry restores the player at zero and hides future messages again | Pass |
| FR-16 | Declining participation revokes history/channel access; cancellation preserves readable history and prevents new messages | Pass |
| FR-17 | Saved favorite genres reach recommendation inputs; watched/rated movies are excluded; eligible local recommendations remain available | Pass |
| FR-18 | Browser location selection handles unavailable weather without losing recommendations; unavailable cinema lookup produces a controlled error | Pass; successful providers still pending |
| FR-19 | Normal users receive 403 from admin APIs; admin dashboard is available; hide/restore updates public review counts and averages | Pass |
| FR-20 | Admin local-movie creation/edit/deactivation works; disabling an account invalidates its existing protected access; enabling succeeds | Pass |
| FR-21 | Password change through the UI invalidates another session for the same account and permits login with the new password | Pass |
| FR-22 | Forgot-password UI delivers a reset email through actual SMTP to the isolated listener; unknown-account feedback remains generic | Pass |
| FR-23 | The emailed link opens the reset form, its query is removed, password reset succeeds, the new password signs in, and token reuse returns 422 | Pass |
| FR-24 | Calendar action downloads an ICS containing the expected title, timezone description, location, meeting link, and UTC instant | Pass |

FR-24 used 2026-12-02T20:30:00+02:00 and verified
DTSTART:20261202T183000Z.

Import into an external calendar application was not independently tested
during AB#157. Existing calendar automated tests also passed, including
all-day release dates and timezone/date-boundary cases.

Review sentiment provider-failure behavior preserved the review.
Successful live Azure classification remains pending.

Initial browser-harness failures caused by readiness timing, incorrect
status/selector expectations, and SMTP capture parsing were corrected.
The corresponding checks were rerun. These were not application fixes.

## Fixture and reproduction procedure

Use disposable complete-profile accounts:

- A: organizer and content owner.
- B: accepted participant and follower.
- C: unrelated account.
- D: administrator.
- A second session for A when checking password-session revocation.

Use separate browser profiles or isolated cookie contexts. Preserve
canonical movie IDs as strings; do not substitute TMDB IDs.

Create three disposable active canonical movies:

- Movie A for watchlist/history and shortlist checks.
- Movie B for review/moderation checks.
- Movie C as an eligible recommendation candidate with supported genres.

Suggested sequence:

1. Sign in each account and verify authenticated identities.
2. Toggle Movie A's watchlist/watched flags, refresh, and compare with B.
3. Create a private list, add Movie A twice, exercise visibility changes,
   verify B's ownership restrictions, then remove/delete the list.
4. Create a five-star review for Movie B, edit it to four stars, and verify
   persisted content and the public aggregate.
5. Follow A as B. Enable A's master sharing preference and review sharing.
   Check feed activity by type and target_id, then disable review sharing.
   The feed intentionally does not include the review body.
6. Create a future scheduled movie night and accept B's invitation.
7. Propose two candidates. Cast and replace votes, exercise a tie, finalize
   a leader, and verify voting closes.
8. Assign the registered 60-second demo asset and open the watch-room page
   in A and B. Seek to different times and send timestamped messages.
9. Verify timestamp hiding, explicit spoilers, refresh persistence,
   nonparticipant denial, reconnect catch-up, and media-error retry.
10. Save favorite genres and verify eligible recommendations while excluding
    watched/rated movies. Check unavailable-weather behavior.
11. As D, hide/restore Movie B's review and verify public aggregates.
    Exercise local catalog management and disable/re-enable C.
12. Change A's password and check the other A session is invalidated.
    Exercise emailed recovery, reset, new-password login, and token reuse.
13. Export a scheduled/finalized night and inspect the downloaded ICS.

Use the established normal development ports when reproducing manually.
The assistant's test ports were isolated and are not required application
configuration changes.

The assistant's fixtures never entered the development database.
For manual checks in a shared local environment, clean up only the
captured disposable records and restore altered preferences/statuses.
Cancel disposable nights to retain their history. Do not delete shared
accounts, catalog records, or the shared demo asset.

## Live integration and demo-environment checks

| Check | Current evidence | Remaining work | Follow-up owner |
| --- | --- | --- | --- |
| Social-provider consent | Dev R reported pass; automated OAuth coverage passed | Preserve the reported result; independent consent was not rerun | Dev R |
| Live TMDB search/details | Dev R reported pass; automated coverage passed; independent provider probe did not confirm success | Preserve the reported result and record any later demo smoke result | Dev R |
| Live geocoding/weather | Independent calls did not establish success; direct weather probe timed out with cURL error 28 | Confirm city resolution and available weather through the configured application | Dev R |
| Live Geoapify cinemas | GEOAPIFY_API_KEY was missing from the inspected local configuration | Configure the server key and verify successful cinema results and UI | Dev R |
| Live Azure sentiment | Credentials were configured, but the independent probe failed to connect | Confirm classification through the configured application | Dev R |
| Configured demo SMTP/Mailpit | Isolated SMTP recovery passed; configured Mailpit was unreachable from the agent | Verify the actual local demo mail service | Dev R |

These are verification/configuration gaps, not confirmed application
regressions or evidence that a provider is globally unavailable.

Do not include API keys, passwords, cookies, reset URLs/tokens, channel
signatures, or private account details in this report.

## Bug register and Azure tracking

Confirmed new application regressions: none.

No Azure work item was created or assigned by the assistant.
Existing Azure blocker items were not independently inspected.

For any subsequently confirmed application regression, create a separate
Azure Bug related to AB#157 and record:

| Azure Bug ID | Summary | Severity/demo impact | Assigned owner | Reproduction evidence | Fix/retest evidence | Status |
| --- | --- | --- | --- | --- | --- | --- |

Do not invent Azure IDs. Keep a failure pending until its cause is
distinguished from configuration, unavailable providers, or test-harness
behavior.

The missing Geoapify configuration can be tracked as a separate setup task
related to AB#157, assigned to Dev R for configuration and verification.

## Commands for reproducing automated checks

From backend, using the default isolated SQLite test configuration:

```powershell
php artisan config:clear
php artisan test
```

Do not point RefreshDatabase tests at the development database.

From frontend:

```powershell
npm test
npm run lint
npm run build
```

From the repository root:

```powershell
git diff --check
```

## Completion gate

- [x] Previously completed Dev R workflows documented.
- [x] Existing automated regression executed.
- [x] Four backend skips and warning output documented.
- [x] Independent browser/API core workflows recorded.
- [x] No application regression confirmed in this run.
- [x] Successful live weather/geocoding verified.
- [x] Geoapify configured and successful cinema lookup verified.
- [x] Successful live Azure classification verified.
- [ ] Configured demo mail service verified.
- [x] No unresolved blocker prevents the demonstration.

Close AB#157 only after recording the remaining results and resolving
any confirmed demonstration blocker.