# Clark County Registered Voter List File Layout

Source: Clark County Election Department, `VoterRequestsTV/TechDocs/voterlistfilelayout.pdf`
(mirrored at clarkcountynv.gov, title "VoterListFileLayout.xls"). Captured 2026-10-30.

Files are ASCII, comma-delimited, zipped. Total record width 1716.

| # | Field Name | Type | Max |
|---|------------|------|-----|
| 1 | STATUS | Text | 1 |
| 2 | PRECINCT_NUM | Text | 50 |
| 3 | CONGRESS | Text | 50 |
| 4 | ASSEMBLY | Text | 50 |
| 5 | SENATE | Text | 50 |
| 6 | COMMISSION | Text | 50 |
| 7 | EDUCATION | Text | 50 |
| 8 | REGENT | Text | 50 |
| 9 | SCHOOL | Text | 50 |
| 10 | CITY | Text | 50 |
| 11 | WARD | Text | 50 |
| 12 | TOWNSHIP | Text | 50 |
| 13 | FIRST_NAME | Text | 50 |
| 14 | MIDDLE_NAME | Text | 50 |
| 15 | LAST_NAME | Text | 50 |
| 16 | NAME_SUFFIX | Text | 15 |
| 17 | SEX | Text | 1 |
| 18 | PARTY_REG | Text | 50 |
| 19 | BIRTH_YEAR | Text | 4 |
| 20 | PHONE_NUM | Text | 50 |
| 21 | RES_STREET_NUM | Text | 10 |
| 22 | RES_DIRECTION | Text | 10 |
| 23 | RES_STREET_NAME | Text | 50 |
| 24 | RES_ADDRESS_TYPE | Text | 50 |
| 25 | RES_UNIT | Text | 50 |
| 26 | RES_CITY | Text | 100 |
| 27 | RES_STATE | Text | 2 |
| 28 | RES_ZIP_CODE | Text | 15 |
| 29 | MAIL_ADDRESS | Text | 256 |
| 30 | MAIL_CITY | Text | 100 |
| 31 | MAIL_STATE | Text | 2 |
| 32 | MAIL_ZIP_CODE | Text | 15 |
| 33 | ACTIVITY_DATE | Text | 10 |
| 34 | REGISTRATION_DATE | Text | 10 |
| 35 | REGISTRATION_NUM | Text | 10 |
| 36 | LANGUAGE_PREF | Text | 50 |
| 37 | POLLING_CODE | Text | 3 |
| 38 | CONFIDENTIAL_FLAG | Text | 1 |
| 39 | ID_NOT_REQD | Text | 1 |
| 40 | AFFIDAVIT | Text | 50 |
| 41-80 | ELECTION1 / VOTE_TYPE1 ... ELECTION20 / VOTE_TYPE20 | Text | 4 / 1 |

Note from the county: address and phone fields are not provided when the voter has requested
confidentiality, indicated by `Y` in `CONFIDENTIAL_FLAG`. The platform passes those through as
blank and never back-fills them (see compliance section of the spec).

Some county exports carry a leading `IDNUMBER` column (the voter record unique identifier used by
the mail ballot and early vote files). The parser uses `IDNUMBER` when present and falls back to
`REGISTRATION_NUM`. Header names are matched case-insensitively with aliases in
`config/clark_layout.php`.

Download locations (all under `https://elections.clarkcountynv.gov/VoterRequestsTV/`):

- `COUNTY_all_TV.zip`, `COUNTY_active_TV.zip`
- `CONGRESS_{n}_all_TV.zip`, `SENATE_{n}_all_TV.zip`, `ASSEMBLY_{n}_all_TV.zip`,
  `COMMISSION_{A-G}_all_TV.zip`, `EDUCATION_{n}_all_TV.zip`, `SCHOOL_{A-G}_all_TV.zip`,
  `CITY_NLV_all_TV.zip` (and `_active_` variants)
