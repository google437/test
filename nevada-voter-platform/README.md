# Nevada Voter Data Platform (PHP)

A Nevada-only voter data and field organizing platform, built from the "Developer Spec v0.1".
It loads the public Clark County voter file, refreshes it automatically, and gives campaigns
list building, maps and turf cutting, an offline canvass app, a phone bank, contact history,
reporting, logged exports, and an admin console for import runs, data health and user access.

Stack: PHP 8.2+ (no framework, no Composer dependencies), PostgreSQL 16 (PostGIS optional),
MapLibre GL with free OpenFreeMap tiles, a PWA service worker for offline canvassing.

## Quick start (local)

```bash
cp .env.example .env            # edit DATABASE_* and APP_DB_* and set APP_KEY
php bin/nvvp key                # paste the output into APP_KEY
createdb nvvp                   # or use docker compose up -d db
php bin/nvvp setup              # schema, row-level security policies, web app role
php bin/nvvp demo               # synthetic Clark file + demo org, users, scripts, surveys
php -S 0.0.0.0:8080 -t public public/index.php
```

Sign in at http://localhost:8080 as `admin@demo.org` / `demo-pass-1234` (or `staff@example.org`
for the admin console). Every account enrolls an authenticator app at first login.

With Docker: `docker compose up --build` starts Postgres (with PostGIS), the web app on port 8080
and a scheduler container that runs `bin/ingest run-due` every 15 minutes. Run `setup` and `demo`
inside the web container (`docker compose exec web php bin/nvvp setup`).

## Layout

```
public/           front controller (index.php), assets, service worker, manifest, robots.txt
src/Core          config, PDO wrapper, router, auth + sessions, TOTP, CSRF, rate limits, audit, alerts
src/Services      list query builder, turf cutting, geocoder (Census), exports
src/Services/Ingest   the pipeline: Fetcher, Archive, ClarkVoterParser, Validator, Differ, Applier,
                  Enricher, Reporter, Pipeline; BallotFiles (mail + early vote), StatusFiles,
                  SosParser (statewide list), Scheduler, SampleGenerator
src/Controllers   web app + JSON API + admin console
src/Views         PHP templates
bin/ingest        ingestion worker CLI        bin/nvvp   admin CLI
db/schema.sql     tables and helper functions db/policies.sql   grants + row-level security
config/clark_layout.php   header aliases and code tables for the county files
docs/             Clark County file layouts and code reference (committed per the spec)
tests/run.php     integration tests (ingestion diff, RLS isolation, turf cutting, sync, TOTP)
```

## Ingestion pipeline

`bin/ingest clark-voters` runs the nine steps from the spec: check (HEAD, ETag / Last-Modified),
fetch (named user agent, retries with backoff, timeout, SHA-256), archive (immutable copy under
`storage/raw/{source}/{date}/{sha}.zip`), parse (streamed from the zip into `staging_voters`
with COPY), validate (row delta over 3 %, missing columns, unknown codes over 0.5 % → run is
`held`), diff (new / changed / unchanged by county voter id), apply (one transaction: upsert
voters, append `voter_changes`, add vote history, mark dropped voters `removed`), enrich
(households, elections, vote counts, current-election flags) and report (Slack + email + admin
console). Campaign data hangs off `voters.id` and is never touched by a refresh.

```
bin/ingest clark-voters [--file=path] [--force]   bin/ingest clark-mail / clark-ev [--file=]
bin/ingest clark-inactive / clark-canceled        bin/ingest sos-upload --file=path
bin/ingest run-due        (cron: */15 * * * *)    bin/ingest schedule
bin/ingest approve|reject|rerun --run=ID          bin/ingest geocode [--limit=10000]
bin/ingest sample --out=f.zip [--rows=N --mutate] bin/ingest runs
```

Schedule (handled by `run-due`): Clark voter list daily 06:00 PT; inactivated / canceled daily
06:15 PT; mail ballot file weekly, every 4 h inside the current election window; early vote file
every 2 h during early voting; SOS statewide list is a monthly reminder with manual upload.
Election windows are set in the admin console (Elections tab) or `bin/nvvp window:set`.

Held runs are approved or rejected in the admin console (or `bin/ingest approve --run=ID`).
The web app only marks a run approved; the worker applies it on its next pass, so the web role
never writes to the voter file.

Before production: email ELINFO@ClarkCountyNV.gov to confirm weekly automated downloads are
acceptable, and set `INGEST_USER_AGENT` to something that identifies you.

## Compliance controls (how the spec's guardrails are implemented)

- Verified election users only: organizations carry type, committee ID, attestation timestamp and
  contract dates. Access ends when `status` is not `active`; `bin/nvvp retention` ends orgs past
  their contract end, revokes sessions, and deletes their campaign data on the retention date.
- Never public: every route requires a session with MFA; `robots.txt` and `X-Robots-Tag` deny
  indexing; the JSON API needs the session cookie plus a CSRF header.
- District scoping in the database: `db/policies.sql` puts row-level security on `voters`
  (precincts of the org's licensed districts), on everything that hangs off voters, and on every
  campaign table (`org_id` match). The web app connects as `nvvp_app`, a non-owner role, with
  `app.org_id` / `app.user_id` / `app.is_staff` set per request. `tests/run.php` proves
  cross-org access fails.
- Exports: logged in `exports` and `audit_log` with user, filter, row count, IP and time; capped
  by `EXPORT_MAX_ROWS`; an admin alert fires at `EXPORT_ALERT_ROWS`.
- Confidential fields pass through blank (the county's `CONFIDENTIAL_FLAG`); nothing re-fills them.
- No voter PII in logs: the app logs exception class, message and location only.
- Rate limits on login, MFA, search and the count API; sessions expire after `SESSION_HOURS` (12)
  and are revocable (disable user, reset MFA).

## Features (v1)

- List builder with live counts (district, precinct, party, status, age, sex, registration date,
  vote history, mail ballot / early vote, household size, phone, tags, survey answers, contact
  results, not-contacted-since) saved as dynamic lists or frozen snapshots; chase mode.
- Map (MapLibre) of list households with precinct-aware turf cutting: auto-split into turfs of
  about N doors, or draw a polygon; assign turfs; print walk sheets (browser print → PDF).
- Canvass PWA: turf list, household view with script and survey, one-tap results, offline queue
  in IndexedDB that syncs idempotently (`client_id`) when back online.
- Phone bank: call queue per list, click-to-dial, script and survey, results written to the same
  contact history.
- Voter profile with file data, vote history, ballot activity, file changes, campaign contact
  timeline, tags and notes. Reports: results by channel, daily activity, survey results, support by
  precinct, volunteer leaderboard, survey CSV export.
- Admin console: source health, import runs with approve / reject and error rows, org onboarding
  (committee, attestation, licensed districts, contract dates), users and MFA resets, audit log and
  export log, election windows, published-total validation setting.

## Geocoding and maps

`bin/ingest geocode` posts households to the free US Census batch geocoder (10,000 per call) and
stores lat/lng on `households` and `voters`. `GEOCODER_FALLBACK_URL` is a hook for a paid vendor.
`bin/nvvp demo` assigns fake coordinates so maps work without the geocoder. Precinct and district
polygons can be stored in `precincts.geom_geojson` / `districts.geom_geojson` (Phase 3 source
decision is open in the spec). PostGIS is optional; add it later for spatial indexes.

## Tests

`php tests/run.php` resets the configured database and runs 44 checks covering the spec's
acceptance criteria: two weekly loads with a correct new / changed / removed diff and an
idempotent rerun, a held load, election feeds and chase mode, RLS isolation between an
Assembly-district org and another org, turf cutting, offline sync idempotency and retention.
Point `DATABASE_URL` at a disposable database first.

## Known limitations / next steps

- Walk sheets are print-ready HTML (use the browser's Save as PDF); no server-side PDF library.
- Inactivated / canceled file URLs and the SOS NevVoter column names are configurable but were not
  verified against live files (the county's TechDocs path blocks automated fetches).
- Election dates are derived from county codes and can be overridden per election window.
- The open legal question in the spec (hosting the file vs. per-campaign files) is unchanged; the
  importer accepts a `--file` upload for the second model.
- Later phases from the spec (vendor appends, texting, ad audience exports, dialer, scores) are not
  built.

This software is not legal advice; have counsel review the compliance section of the spec.
