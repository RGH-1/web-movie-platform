# Social login and account security verification — AB#84

## Environment

- Commit tested
- Verification date
- Browser and development environment
- Providers configured

## Automated results

| Check | Result |
| --- | --- |
| Focused OAuth, account identity, and password regression tests | Passed |
| Full Laravel suite | Passed |
| Focused React auth and password tests | Passed |
| Full frontend suite | Passed |

Backend checks cover first-time and returning provider identities,
existing-email rejection, missing email, provider failures, OAuth state,
database uniqueness, and access to Sanctum-protected routes after OAuth.
Mocked provider results are recorded separately from live browser checks.

## Live provider results

| Provider | First login | Returning login reused the same local user | Protected profile returned 200 | Logout blocked access |
| --- | --- | --- | --- | --- |
| Google | Pass | Pass | Pass | Pass |
| GitHub | Pass | Pass | Pass | Pass |
| Facebook | Fail | Pending | Pending | Pending |

## Additional browser checks

| Check | Observed result |
| --- | --- |
| Provider cancellation or denial | Pass |
| Existing-email conflict, if exercised live | Pass (doesn't allow duplicate emails) |
| Email/password login, protected profile access, and logout | Pass |

## Open issues

Record any failed or unverified check and its cause. Keep provider
credentials, tokens, cookies, and authorization codes out of the report.

See [OAuth setup](../oauth.md) and the
[authentication API contract](../api/auth-contract.md).

## Facebook live verification

Facebook browser testing remains pending on Dev M’s configured machine.
Mocked Facebook tests passed.

Dev M should verify first login, returning login with the same local user ID,
protected profile access, and logout. Record the tested commit and results here.

Google, GitHub, and the remaining automated and manual checks passed in Dev R’s
environment.