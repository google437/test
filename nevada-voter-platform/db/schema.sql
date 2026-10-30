-- Nevada Voter Data Platform schema (PostgreSQL 16). PostGIS is optional: geometry is kept as
-- lat/lng columns plus GeoJSON so the platform runs without the extension; add PostGIS later
-- for spatial indexes.
CREATE EXTENSION IF NOT EXISTS pgcrypto;
CREATE EXTENSION IF NOT EXISTS citext;

CREATE TABLE IF NOT EXISTS settings (
  key text PRIMARY KEY,
  value text,
  updated_at timestamptz NOT NULL DEFAULT now()
);

-- ======================= Voter file layer (shared, refreshed by the pipeline) =======================
CREATE TABLE IF NOT EXISTS import_runs (
  id bigserial PRIMARY KEY,
  source text NOT NULL,
  status text NOT NULL DEFAULT 'started', -- started,no_change,fetched,parsed,held,approved,loaded,rejected,failed
  source_url text,
  etag text,
  last_modified text,
  sha256 text,
  raw_path text,
  rows_total integer,
  rows_new integer,
  rows_changed integer,
  rows_unchanged integer,
  rows_removed integer,
  rows_error integer,
  unknown_code_pct numeric(6,3),
  hold_reason text,
  summary jsonb,
  approved_by bigint,
  started_at timestamptz NOT NULL DEFAULT now(),
  finished_at timestamptz,
  duration_ms integer
);
CREATE INDEX IF NOT EXISTS import_runs_source_idx ON import_runs(source, started_at DESC);

CREATE TABLE IF NOT EXISTS import_errors (
  id bigserial PRIMARY KEY,
  run_id bigint NOT NULL REFERENCES import_runs(id) ON DELETE CASCADE,
  line_no integer,
  reason text NOT NULL,
  raw jsonb
);
CREATE INDEX IF NOT EXISTS import_errors_run_idx ON import_errors(run_id);

CREATE TABLE IF NOT EXISTS source_state (
  source text PRIMARY KEY,
  last_attempt_at timestamptz,
  last_change_at timestamptz,
  etag text,
  last_modified text,
  sha256 text,
  last_run_id bigint
);

CREATE TABLE IF NOT EXISTS elections (
  code text PRIMARY KEY,            -- e.g. 24G
  election_date date,
  type text NOT NULL,               -- primary, general, special
  name text NOT NULL
);

CREATE TABLE IF NOT EXISTS election_windows (
  id serial PRIMARY KEY,
  name text NOT NULL,
  election_code text NOT NULL,
  registration_close date,
  early_vote_start date,
  early_vote_end date,
  election_date date NOT NULL,
  is_current boolean NOT NULL DEFAULT false
);

CREATE TABLE IF NOT EXISTS districts (
  id serial PRIMARY KEY,
  county text NOT NULL DEFAULT 'clark',
  type text NOT NULL,               -- congress, senate, assembly, commission, education, regent, school, city, ward, township
  name text NOT NULL,
  geom_geojson jsonb,
  UNIQUE (county, type, name)
);

CREATE TABLE IF NOT EXISTS precincts (
  id serial PRIMARY KEY,
  county text NOT NULL DEFAULT 'clark',
  number text NOT NULL,
  geom_geojson jsonb,
  UNIQUE (county, number)
);

CREATE TABLE IF NOT EXISTS precinct_districts (
  precinct_id integer NOT NULL REFERENCES precincts(id) ON DELETE CASCADE,
  district_id integer NOT NULL REFERENCES districts(id) ON DELETE CASCADE,
  PRIMARY KEY (precinct_id, district_id)
);
CREATE INDEX IF NOT EXISTS precinct_districts_district_idx ON precinct_districts(district_id);

CREATE TABLE IF NOT EXISTS households (
  id bigserial PRIMARY KEY,
  county text NOT NULL DEFAULT 'clark',
  address_key text NOT NULL UNIQUE,
  address_line text,
  city text,
  zip text,
  lat double precision,
  lng double precision,
  voter_count integer NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS households_latlng_idx ON households(lat, lng);

CREATE TABLE IF NOT EXISTS voters (
  id bigserial PRIMARY KEY,
  source_county text NOT NULL DEFAULT 'clark',
  county_voter_id text NOT NULL,
  sos_voter_id text,
  first_name text, middle_name text, last_name text, suffix text,
  sex text,
  birth_year integer,
  party text,
  status text NOT NULL DEFAULT 'A',          -- A active, I inactive, P17 pre-registered, removed
  registration_date date,
  activity_date date,
  phone text,
  confidential boolean NOT NULL DEFAULT false,
  language_pref text,
  precinct_id integer REFERENCES precincts(id),
  household_id bigint REFERENCES households(id),
  res_street_num text, res_direction text, res_street_name text, res_address_type text, res_unit text,
  res_city text, res_state text, res_zip text,
  mail_address text, mail_city text, mail_state text, mail_zip text,
  lat double precision, lng double precision, geocode_quality text,
  vote_count_general smallint NOT NULL DEFAULT 0,
  vote_count_primary smallint NOT NULL DEFAULT 0,
  last_voted_election text,
  mail_requested_current boolean NOT NULL DEFAULT false,
  mail_returned_current boolean NOT NULL DEFAULT false,
  early_voted_current boolean NOT NULL DEFAULT false,
  voted_current boolean NOT NULL DEFAULT false,
  first_seen_run bigint,
  last_seen_run bigint,
  removed_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (source_county, county_voter_id)
);
CREATE INDEX IF NOT EXISTS voters_precinct_idx ON voters(precinct_id);
CREATE INDEX IF NOT EXISTS voters_household_idx ON voters(household_id);
CREATE INDEX IF NOT EXISTS voters_party_idx ON voters(party);
CREATE INDEX IF NOT EXISTS voters_status_idx ON voters(status);
CREATE INDEX IF NOT EXISTS voters_lastname_idx ON voters(upper(last_name), upper(first_name));

CREATE TABLE IF NOT EXISTS voter_addresses (
  id bigserial PRIMARY KEY,
  voter_id bigint NOT NULL REFERENCES voters(id) ON DELETE CASCADE,
  address_line text, city text, state text, zip text,
  mail_address text, mail_city text, mail_state text, mail_zip text,
  lat double precision, lng double precision, geocode_quality text,
  valid_from timestamptz NOT NULL DEFAULT now(),
  valid_to timestamptz
);
CREATE INDEX IF NOT EXISTS voter_addresses_voter_idx ON voter_addresses(voter_id);

CREATE TABLE IF NOT EXISTS voter_changes (
  id bigserial PRIMARY KEY,
  voter_id bigint NOT NULL REFERENCES voters(id) ON DELETE CASCADE,
  run_id bigint REFERENCES import_runs(id),
  field text NOT NULL,
  old_value text,
  new_value text,
  changed_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS voter_changes_voter_idx ON voter_changes(voter_id);
CREATE INDEX IF NOT EXISTS voter_changes_run_idx ON voter_changes(run_id);

CREATE TABLE IF NOT EXISTS vote_history (
  voter_id bigint NOT NULL REFERENCES voters(id) ON DELETE CASCADE,
  election_code text NOT NULL,
  vote_type text,                          -- P, M, E, F, Z
  PRIMARY KEY (voter_id, election_code)
);
CREATE INDEX IF NOT EXISTS vote_history_election_idx ON vote_history(election_code);

CREATE TABLE IF NOT EXISTS ballot_activity (
  voter_id bigint NOT NULL REFERENCES voters(id) ON DELETE CASCADE,
  election_code text NOT NULL,
  mail_requested boolean NOT NULL DEFAULT false,
  mail_sent_date date,
  mail_returned boolean NOT NULL DEFAULT false,
  mail_returned_date date,
  return_code text,
  ev_voted_on timestamptz,
  ev_site text,
  source_run bigint,
  updated_at timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (voter_id, election_code)
);

-- Parsed rows of one run, kept until the run is loaded or rejected so a held run can be approved later.
CREATE TABLE IF NOT EXISTS staging_voters (
  run_id bigint NOT NULL REFERENCES import_runs(id) ON DELETE CASCADE,
  line_no integer NOT NULL,
  source_county text NOT NULL DEFAULT 'clark',
  county_voter_id text NOT NULL,
  status text, precinct text,
  congress text, assembly text, senate text, commission text, education text, regent text, school text,
  city text, ward text, township text,
  first_name text, middle_name text, last_name text, suffix text, sex text,
  party text, birth_year integer, phone text,
  res_street_num text, res_direction text, res_street_name text, res_address_type text, res_unit text,
  res_city text, res_state text, res_zip text,
  mail_address text, mail_city text, mail_state text, mail_zip text,
  activity_date date, registration_date date, language_pref text, confidential boolean,
  vote_pairs text,                          -- "24G:M|22G:E|..."
  diff_class text,                          -- new, changed, unchanged
  PRIMARY KEY (run_id, line_no)
);
CREATE INDEX IF NOT EXISTS staging_voters_cvid_idx ON staging_voters(run_id, county_voter_id);

-- ======================= Campaign layer (per organization, row-level security) =======================
CREATE TABLE IF NOT EXISTS orgs (
  id bigserial PRIMARY KEY,
  name text NOT NULL,
  type text NOT NULL,                       -- candidate_committee, pac, party, ballot_question_committee, consultant
  committee_id text,
  use_attestation_at timestamptz,
  attested_by text,
  contract_start date,
  contract_end date,
  status text NOT NULL DEFAULT 'active',    -- active, ended, archived
  data_retention_until date,
  created_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS org_districts (
  org_id bigint NOT NULL REFERENCES orgs(id) ON DELETE CASCADE,
  district_id integer NOT NULL REFERENCES districts(id) ON DELETE CASCADE,
  PRIMARY KEY (org_id, district_id)
);

CREATE TABLE IF NOT EXISTS users (
  id bigserial PRIMARY KEY,
  email citext NOT NULL UNIQUE,
  name text NOT NULL,
  password_hash text NOT NULL,
  totp_secret_enc text,
  mfa_enabled boolean NOT NULL DEFAULT false,
  is_staff boolean NOT NULL DEFAULT false,
  status text NOT NULL DEFAULT 'active',    -- active, disabled
  must_change_password boolean NOT NULL DEFAULT false,
  last_login_at timestamptz,
  created_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS memberships (
  org_id bigint NOT NULL REFERENCES orgs(id) ON DELETE CASCADE,
  user_id bigint NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  role text NOT NULL,                        -- admin, manager, organizer, volunteer
  PRIMARY KEY (org_id, user_id)
);

CREATE TABLE IF NOT EXISTS sessions (
  id text PRIMARY KEY,
  user_id bigint NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  org_id bigint REFERENCES orgs(id) ON DELETE SET NULL,
  mfa_passed boolean NOT NULL DEFAULT false,
  ip text,
  user_agent text,
  created_at timestamptz NOT NULL DEFAULT now(),
  last_seen_at timestamptz NOT NULL DEFAULT now(),
  expires_at timestamptz NOT NULL,
  revoked_at timestamptz
);
CREATE INDEX IF NOT EXISTS sessions_user_idx ON sessions(user_id);

CREATE TABLE IF NOT EXISTS rate_limits (
  key text NOT NULL,
  window_start timestamptz NOT NULL,
  count integer NOT NULL DEFAULT 0,
  PRIMARY KEY (key, window_start)
);

CREATE TABLE IF NOT EXISTS lists (
  id bigserial PRIMARY KEY,
  org_id bigint NOT NULL REFERENCES orgs(id) ON DELETE CASCADE,
  name text NOT NULL,
  filters jsonb NOT NULL DEFAULT '{}'::jsonb,
  is_frozen boolean NOT NULL DEFAULT false,
  chase_mode boolean NOT NULL DEFAULT false,
  member_count integer,
  door_count integer,
  created_by bigint REFERENCES users(id),
  created_at timestamptz NOT NULL DEFAULT now(),
  updated_at timestamptz NOT NULL DEFAULT now(),
  frozen_at timestamptz
);
CREATE INDEX IF NOT EXISTS lists_org_idx ON lists(org_id);

CREATE TABLE IF NOT EXISTS list_members (
  list_id bigint NOT NULL REFERENCES lists(id) ON DELETE CASCADE,
  voter_id bigint NOT NULL REFERENCES voters(id) ON DELETE CASCADE,
  org_id bigint NOT NULL REFERENCES orgs(id) ON DELETE CASCADE,
  PRIMARY KEY (list_id, voter_id)
);

CREATE TABLE IF NOT EXISTS scripts (
  id bigserial PRIMARY KEY,
  org_id bigint NOT NULL REFERENCES orgs(id) ON DELETE CASCADE,
  name text NOT NULL,
  channel text NOT NULL DEFAULT 'any',      -- door, phone, any
  body text NOT NULL,
  is_default boolean NOT NULL DEFAULT false,
  created_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS turfs (
  id bigserial PRIMARY KEY,
  org_id bigint NOT NULL REFERENCES orgs(id) ON DELETE CASCADE,
  list_id bigint NOT NULL REFERENCES lists(id) ON DELETE CASCADE,
  name text NOT NULL,
  geom_geojson jsonb,
  assigned_user_id bigint REFERENCES users(id),
  script_id bigint REFERENCES scripts(id),
  door_count integer NOT NULL DEFAULT 0,
  voter_count integer NOT NULL DEFAULT 0,
  status text NOT NULL DEFAULT 'open',      -- open, in_progress, done
  created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS turfs_org_idx ON turfs(org_id);

CREATE TABLE IF NOT EXISTS turf_households (
  turf_id bigint NOT NULL REFERENCES turfs(id) ON DELETE CASCADE,
  household_id bigint NOT NULL REFERENCES households(id) ON DELETE CASCADE,
  org_id bigint NOT NULL REFERENCES orgs(id) ON DELETE CASCADE,
  walk_order integer NOT NULL DEFAULT 0,
  PRIMARY KEY (turf_id, household_id)
);

CREATE TABLE IF NOT EXISTS contact_attempts (
  id bigserial PRIMARY KEY,
  org_id bigint NOT NULL REFERENCES orgs(id) ON DELETE CASCADE,
  voter_id bigint NOT NULL REFERENCES voters(id) ON DELETE CASCADE,
  user_id bigint REFERENCES users(id),
  turf_id bigint REFERENCES turfs(id) ON DELETE SET NULL,
  list_id bigint REFERENCES lists(id) ON DELETE SET NULL,
  channel text NOT NULL,                    -- door, phone, text
  result text NOT NULL,                     -- not_home, refused, moved, contacted, deceased, wrong_number, left_message
  notes text,
  lat double precision, lng double precision,
  client_id text,                           -- idempotency key from the offline canvass app
  contacted_at timestamptz NOT NULL DEFAULT now(),
  created_at timestamptz NOT NULL DEFAULT now(),
  UNIQUE (org_id, client_id)
);
CREATE INDEX IF NOT EXISTS contact_attempts_voter_idx ON contact_attempts(org_id, voter_id);
CREATE INDEX IF NOT EXISTS contact_attempts_user_idx ON contact_attempts(org_id, user_id, contacted_at);

CREATE TABLE IF NOT EXISTS survey_questions (
  id bigserial PRIMARY KEY,
  org_id bigint NOT NULL REFERENCES orgs(id) ON DELETE CASCADE,
  question text NOT NULL,
  qtype text NOT NULL DEFAULT 'choice',     -- scale (1-5), choice, yesno, text
  options jsonb,
  sort_order integer NOT NULL DEFAULT 0,
  active boolean NOT NULL DEFAULT true
);

CREATE TABLE IF NOT EXISTS survey_responses (
  id bigserial PRIMARY KEY,
  org_id bigint NOT NULL REFERENCES orgs(id) ON DELETE CASCADE,
  contact_attempt_id bigint NOT NULL REFERENCES contact_attempts(id) ON DELETE CASCADE,
  question_id bigint NOT NULL REFERENCES survey_questions(id) ON DELETE CASCADE,
  answer text
);
CREATE INDEX IF NOT EXISTS survey_responses_q_idx ON survey_responses(org_id, question_id);

CREATE TABLE IF NOT EXISTS voter_tags (
  org_id bigint NOT NULL REFERENCES orgs(id) ON DELETE CASCADE,
  voter_id bigint NOT NULL REFERENCES voters(id) ON DELETE CASCADE,
  tag text NOT NULL,
  created_by bigint REFERENCES users(id),
  created_at timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (org_id, voter_id, tag)
);

CREATE TABLE IF NOT EXISTS notes (
  id bigserial PRIMARY KEY,
  org_id bigint NOT NULL REFERENCES orgs(id) ON DELETE CASCADE,
  voter_id bigint NOT NULL REFERENCES voters(id) ON DELETE CASCADE,
  user_id bigint REFERENCES users(id),
  body text NOT NULL,
  created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS notes_voter_idx ON notes(org_id, voter_id);

CREATE TABLE IF NOT EXISTS exports (
  id bigserial PRIMARY KEY,
  org_id bigint NOT NULL REFERENCES orgs(id) ON DELETE CASCADE,
  user_id bigint REFERENCES users(id),
  kind text NOT NULL,                       -- list_csv, survey_csv
  filters jsonb,
  row_count integer NOT NULL,
  ip text,
  created_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS audit_log (
  id bigserial PRIMARY KEY,
  org_id bigint,
  user_id bigint,
  action text NOT NULL,
  details jsonb,
  ip text,
  created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS audit_log_org_idx ON audit_log(org_id, created_at DESC);
CREATE INDEX IF NOT EXISTS audit_log_action_idx ON audit_log(action, created_at DESC);

-- ======================= Helper functions used by policies =======================
CREATE OR REPLACE FUNCTION app_org_id() RETURNS bigint LANGUAGE sql STABLE AS $$
  SELECT NULLIF(current_setting('app.org_id', true), '')::bigint
$$;
CREATE OR REPLACE FUNCTION app_is_staff() RETURNS boolean LANGUAGE sql STABLE AS $$
  SELECT COALESCE(current_setting('app.is_staff', true), '') = '1'
$$;
-- Precincts the current org is licensed for (through its licensed districts).
CREATE OR REPLACE FUNCTION app_licensed_precincts() RETURNS SETOF integer LANGUAGE sql STABLE AS $$
  SELECT pd.precinct_id FROM precinct_districts pd
  JOIN org_districts od ON od.district_id = pd.district_id
  WHERE od.org_id = app_org_id()
$$;

-- Normalized residence address key; the single definition used to group voters into households.
CREATE OR REPLACE FUNCTION address_key(num text, dir text, name text, typ text, unit text, city text, zip text)
RETURNS text LANGUAGE sql IMMUTABLE AS $$
  SELECT upper(regexp_replace(btrim(concat_ws(' ', NULLIF(btrim(num),''), NULLIF(btrim(dir),''), NULLIF(btrim(name),''),
         NULLIF(btrim(typ),''), NULLIF(btrim(unit),''), NULLIF(btrim(city),''), NULLIF(left(btrim(zip),5),''))), '\s+', ' ', 'g'))
$$;
