# Clark County Early Vote Return File Layout

Source: `evlayout.pdf`. Captured 2026-10-30.

| Field | Comment |
|-------|---------|
| IDNUMBER | Clark County voter record unique identifier |
| NAME | Voter name |
| PRECINCT | Precinct |
| PARTY | Ballot party code (NA for general) |
| PARTY ABBR | Ballot party abbreviation (NA for general) |
| CONGRESS, ASSEMBLY, SENATE, COMMISSION, EDUCATION, REGENT, SCHOOL, WARD, TOWNSHIP | Voting districts |
| STATUS | A active, I inactive, CH challenged, CP citizen pending, AP active pending |
| EV SITE | Early vote site the voter voted at |
| ELECTION CODE | e.g. `26PE` 2026 primary, `26GE` 2026 general |
| ACTIVITY DATE | Date and time the voter voted |

Ballot party for primaries: 01 Democrat ballot, 02 Republican ballot, 03 all other parties
(non-partisan ballot). General elections carry NA.

Note the election code in this file may carry a trailing `E` (`26GE`); the platform normalizes it
to the voter-list form (`26G`).
