# Clark County Registered Voter List Data File Code References

Source: `VoterRequestsTV/TechDocs/voterlistcodereference.pdf`. Captured 2026-10-30.

| Field | Code | Meaning |
|-------|------|---------|
| STATUS | A | Active |
| STATUS | I | Inactive |
| PARTY_REG | DEM | Democrat |
| PARTY_REG | REP | Republican |
| PARTY_REG | NP | Non-partisan |
| PARTY_REG | LIB | Libertarian |
| PARTY_REG | INA | Independent American |
| PARTY_REG | OTH | Other |
| VOTE_TYPE (1-20) | P | Election day voted |
| VOTE_TYPE (1-20) | M | Mail ballot voted |
| VOTE_TYPE (1-20) | E | Early voted |
| VOTE_TYPE (1-20) | F | Federal write-in voted |
| VOTE_TYPE (1-20) | Z | Provisionally voted |
| ELECTION (1-20) | ##P / ##G | Two-digit year followed by P (primary) or G (general). `10G` = 2010 general |

Codes are not necessarily in the order they appear in the file.
