# Clark County Mail Ballot Return File Layout

Source: `mblayout.pdf` ("MB_cumulative_File_Specs"). Captured 2026-10-30.

| Field | Comment |
|-------|---------|
| IDNUMBER | Clark County voter record unique identifier |
| VOTER_NAME | Voter full name |
| STREETNUMBER | Residential street number |
| STREETPREDIR | Residential street pre-direction |
| STREETNAME | Residential street name |
| STREETTYPE | Residential street type |
| UNIT | Residential unit |
| CITY | Residential city |
| STATE | Residential state |
| ZIP | Residential zip |
| PRECINCT | Ballot precinct |
| VOTER_REG_PARTY | Registered party |
| BALLOT_PARTY | Party of the ballot issued (partisan primary only) |
| ELECTION_CODE | Election code, e.g. `10G` |
| REQUEST_SOURCE | NA |
| REQUEST_DATE | NA |
| BALLOT_MAIL_DATE | Date the ballot was mailed to the voter |
| BALLOT_RECEIVE_DATE | Date the ballot was received back by the Election Department |
| RETURN_CODE | Returned ballot code |

A row in this file means a ballot was issued (mail_requested). A non-empty `BALLOT_RECEIVE_DATE`
means it came back (mail_returned).
