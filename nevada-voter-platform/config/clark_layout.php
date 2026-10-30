<?php
/**
 * Clark County voter list layout. Header names are matched case-insensitively after trimming;
 * each internal field lists the accepted header aliases. See docs/clark-voter-list-layout.md.
 */
return [
    'id_fields' => ['IDNUMBER', 'REGISTRATION_NUM', 'VOTER_ID'],
    'required' => ['STATUS', 'PRECINCT_NUM', 'FIRST_NAME', 'LAST_NAME', 'PARTY_REG', 'BIRTH_YEAR', 'REGISTRATION_DATE'],
    'fields' => [
        'status' => ['STATUS'],
        'precinct' => ['PRECINCT_NUM', 'PRECINCT'],
        'congress' => ['CONGRESS'],
        'assembly' => ['ASSEMBLY'],
        'senate' => ['SENATE'],
        'commission' => ['COMMISSION'],
        'education' => ['EDUCATION'],
        'regent' => ['REGENT'],
        'school' => ['SCHOOL'],
        'city' => ['CITY'],
        'ward' => ['WARD'],
        'township' => ['TOWNSHIP'],
        'first_name' => ['FIRST_NAME'],
        'middle_name' => ['MIDDLE_NAME'],
        'last_name' => ['LAST_NAME'],
        'suffix' => ['NAME_SUFFIX', 'SUFFIX'],
        'sex' => ['SEX'],
        'party' => ['PARTY_REG', 'PARTY'],
        'birth_year' => ['BIRTH_YEAR'],
        'phone' => ['PHONE_NUM', 'PHONE'],
        'res_street_num' => ['RES_STREET_NUM'],
        'res_direction' => ['RES_DIRECTION'],
        'res_street_name' => ['RES_STREET_NAME'],
        'res_address_type' => ['RES_ADDRESS_TYPE'],
        'res_unit' => ['RES_UNIT'],
        'res_city' => ['RES_CITY'],
        'res_state' => ['RES_STATE'],
        'res_zip' => ['RES_ZIP_CODE', 'RES_ZIP'],
        'mail_address' => ['MAIL_ADDRESS'],
        'mail_city' => ['MAIL_CITY'],
        'mail_state' => ['MAIL_STATE'],
        'mail_zip' => ['MAIL_ZIP_CODE', 'MAIL_ZIP'],
        'activity_date' => ['ACTIVITY_DATE'],
        'registration_date' => ['REGISTRATION_DATE'],
        'language_pref' => ['LANGUAGE_PREF'],
        'confidential' => ['CONFIDENTIAL_FLAG'],
    ],
    'vote_history_slots' => 20,
    'codes' => [
        'status' => ['A', 'I'],
        'party' => ['DEM', 'REP', 'NP', 'LIB', 'INA', 'OTH'],
        'vote_type' => ['P', 'M', 'E', 'F', 'Z'],
    ],
    'district_columns' => ['congress', 'assembly', 'senate', 'commission', 'education', 'regent', 'school', 'city', 'ward', 'township'],

    // Mail ballot return file (docs/clark-mail-ballot-layout.md)
    'mail' => [
        'id' => ['IDNUMBER'],
        'election' => ['ELECTION_CODE', 'ELECTION CODE'],
        'mailed' => ['BALLOT_MAIL_DATE'],
        'received' => ['BALLOT_RECEIVE_DATE'],
        'return_code' => ['RETURN_CODE'],
    ],
    // Early vote return file (docs/clark-early-vote-layout.md)
    'ev' => [
        'id' => ['IDNUMBER'],
        'election' => ['ELECTION CODE', 'ELECTION_CODE'],
        'site' => ['EV SITE', 'EV_SITE'],
        'voted_at' => ['ACTIVITY DATE', 'ACTIVITY_DATE'],
    ],
];
