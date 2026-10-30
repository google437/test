-- Row-level security and grants for the web application role. Run as the table owner.
-- The role name is substituted by bin/nvvp setup (default nvvp_app).

GRANT USAGE ON SCHEMA public TO :"approle";

-- Voter-file layer: read-only to campaigns, scoped to licensed districts.
GRANT SELECT ON voters, voter_addresses, voter_changes, vote_history, ballot_activity, households,
  precincts, districts, precinct_districts, elections, election_windows, source_state TO :"approle";
GRANT SELECT, UPDATE ON import_runs TO :"approle";        -- staff approve/reject held runs
GRANT SELECT ON import_errors TO :"approle";
GRANT SELECT, INSERT, UPDATE, DELETE ON election_windows, settings TO :"approle";

-- Campaign layer: full DML, filtered by policies below.
GRANT SELECT, INSERT, UPDATE, DELETE ON orgs, org_districts, users, memberships, sessions, rate_limits,
  lists, list_members, scripts, turfs, turf_households, contact_attempts, survey_questions,
  survey_responses, voter_tags, notes, exports, audit_log TO :"approle";
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO :"approle";

ALTER TABLE voters ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS voters_scope ON voters;
CREATE POLICY voters_scope ON voters FOR SELECT
  USING (app_is_staff() OR precinct_id IN (SELECT app_licensed_precincts()));

ALTER TABLE voter_addresses ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS voter_addresses_scope ON voter_addresses;
CREATE POLICY voter_addresses_scope ON voter_addresses FOR SELECT
  USING (EXISTS (SELECT 1 FROM voters v WHERE v.id = voter_addresses.voter_id));

ALTER TABLE voter_changes ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS voter_changes_scope ON voter_changes;
CREATE POLICY voter_changes_scope ON voter_changes FOR SELECT
  USING (EXISTS (SELECT 1 FROM voters v WHERE v.id = voter_changes.voter_id));

ALTER TABLE vote_history ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS vote_history_scope ON vote_history;
CREATE POLICY vote_history_scope ON vote_history FOR SELECT
  USING (EXISTS (SELECT 1 FROM voters v WHERE v.id = vote_history.voter_id));

ALTER TABLE ballot_activity ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS ballot_activity_scope ON ballot_activity;
CREATE POLICY ballot_activity_scope ON ballot_activity FOR SELECT
  USING (EXISTS (SELECT 1 FROM voters v WHERE v.id = ballot_activity.voter_id));

ALTER TABLE households ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS households_scope ON households;
CREATE POLICY households_scope ON households FOR SELECT
  USING (app_is_staff() OR EXISTS (SELECT 1 FROM voters v WHERE v.household_id = households.id));

-- Campaign tables: a row is visible and writable only inside its own org (staff see all).
DO $$
DECLARE t text;
BEGIN
  FOREACH t IN ARRAY ARRAY['lists','list_members','scripts','turfs','turf_households','contact_attempts',
                           'survey_questions','survey_responses','voter_tags','notes','exports','org_districts']
  LOOP
    EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);

    EXECUTE format('DROP POLICY IF EXISTS %I_org ON %I', t, t);
    EXECUTE format('CREATE POLICY %I_org ON %I USING (app_is_staff() OR org_id = app_org_id()) WITH CHECK (app_is_staff() OR org_id = app_org_id())', t, t);
  END LOOP;
END $$;

ALTER TABLE audit_log ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS audit_log_read ON audit_log;
CREATE POLICY audit_log_read ON audit_log FOR SELECT USING (app_is_staff() OR org_id = app_org_id());
DROP POLICY IF EXISTS audit_log_write ON audit_log;
CREATE POLICY audit_log_write ON audit_log FOR INSERT WITH CHECK (true);

-- The owner role (migrations, ingestion worker) bypasses RLS as table owner.
