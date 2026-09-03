<?php

/*
|--------------------------------------------------------------------------
| CS Form 212 (Revised 2026) — cell map
|--------------------------------------------------------------------------
|
| Every cell reference for the PDS export lives here and nowhere else. When
| the CSC revises the form, this is the one file to open, and it is meant to
| be read with the spreadsheet beside it.
|
| Two things about this template that are easy to get wrong:
|
| 1. The caption usually sits BELOW its input box, not beside it. On C1,
|    I17 takes the house number and I18 holds the words "House/Block/Lot No.".
|    Reading a caption and writing into the cell next to it puts every address
|    field one row out of place, and the result still looks like a filled form.
|
| 2. Values must be written to the TOP-LEFT cell of a merged range. Writing to
|    any other cell in the range is discarded without an error.
|
| Every reference below was read out of the file, not inferred from the 2017
| revision.
|
*/

return [

    'path' => storage_path('app/templates/CS Form No. 212 Revised 2026 Personal Data Sheet PDS.xlsx'),

    /*
     * The CSC supplies its own continuation sheets — C5 to C11 — and the form
     * prints "(Continue on sheet C7 if necessary)" at the foot of each section
     * that has one. The exporter fills those; it does not create sheets.
     */
    'sheets' => [
        'page_1' => 'C1',
        'page_2' => 'C2',
        'page_3' => 'C3',
        'page_4' => 'C4',
        'learning_development_cont' => 'C5_L&D cont.',
        'work_experience_cont' => 'C6_Work Exp cont.',
        // The trailing space is in the file, not a typo here. Excel keeps it,
        // getSheetByName is exact, and without it the lookup returns null.
        'family_background_cont' => 'C7_Family Background cont. ',
        'education_cont' => 'C8_Educ. Background cont.',
        'eligibility_cont' => 'C9_Elig cont.',
        'voluntary_work_cont' => 'C10_Vol. Work cont.',
        'other_information_cont' => 'C11_Other Info cont.',
    ],

    /*
     * Dates print dd/mm/yyyy. The form says so on every date field, and it is
     * the one format a Philippine government reader will not misread. Written
     * as text, never as a spreadsheet date serial, which would render in
     * whatever the reader's locale decides.
     */
    'date_format' => 'd/m/Y',

    /*
     * I. PERSONAL INFORMATION — items 1 to 21, sheet C1.
     */
    'personal_information' => [
        'sheet' => 'page_1',
        'cells' => [
            // 1-2, from the employee master rather than the PDS tables
            'surname' => 'D10',
            'first_name' => 'D11',
            'middle_name' => 'D12',
            'name_extension' => 'N11',

            // 3-9. Sex and civil status are ticks, not text — see 'ticks' below.
            'date_of_birth' => 'D13',
            'place_of_birth' => 'D15',
            'civil_status_other' => 'E20',
            'height_m' => 'D22',
            'weight_kg' => 'D24',
            'blood_type' => 'D25',

            // 10-15
            'umid_id' => 'D27',
            'pagibig_id' => 'D29',
            'philhealth_no' => 'D31',
            'philsys_id' => 'D32',
            'tin_no' => 'D33',
            'agency_employee_no' => 'D34',

            // 16. Citizenship itself is a tick; only the country is typed.
            'dual_citizenship_country' => 'L16',

            // 17 — residential address. Captions sit one row below each box.
            'res_house_no' => 'I17',
            'res_street' => 'L17',
            'res_subdivision' => 'I19',
            'res_barangay' => 'L19',
            'res_city' => 'I22',
            'res_province' => 'L22',
            'res_zip_code' => 'I24',

            // 18 — permanent address
            'perm_house_no' => 'I25',
            'perm_street' => 'L25',
            'perm_subdivision' => 'I27',
            'perm_barangay' => 'L27',
            'perm_city' => 'I29',
            'perm_province' => 'L29',
            'perm_zip_code' => 'I31',

            // 19-21
            'telephone_no' => 'I32',
            'mobile_no' => 'I33',
            'email_address' => 'I34',
        ],
    ],

    /*
     * Checkboxes.
     *
     * The form says so itself at the top of C1: "Tick appropriate boxes (☐)".
     * These are real Excel form controls, each linked to a cell that holds
     * TRUE or FALSE — writing the word "Female" into E16 would replace the
     * control's own value with text and leave the box unticked.
     *
     * The cell for every box was read out of xl/drawings/vmlDrawing1.vml,
     * where each control carries its label and its <x:FmlaLink>. The labels
     * are drawings, not cell values, which is why they cannot be found by
     * reading the sheet.
     *
     * Note what the printed form actually offers for civil status: Single,
     * Married, Widowed, Separated, Other/s. There is no Solo Parent box, so a
     * solo parent ticks Other/s and the word goes in the text cell beside it.
     */
    'ticks' => [
        'citizenship' => [
            'filipino' => 'J13',
            'dual' => 'K13',
        ],
        'dual_citizenship_by' => [
            'by_birth' => 'L14',
            'by_naturalization' => 'M14',
        ],
        'sex' => [
            'male' => 'D16',
            'female' => 'E16',
        ],
        'civil_status' => [
            'single' => 'D17',
            'married' => 'E17',
            'widowed' => 'D18',
            'separated' => 'E19',
            'other' => 'D20',
            // No box of its own on the form.
            'solo_parent' => 'D20',
        ],
    ],

    /*
     * II. FAMILY BACKGROUND — items 22, 24 and 25, sheet C1.
     * Item 23, the children, is a list and is described separately below.
     */
    'family_background' => [
        'sheet' => 'page_1',
        'cells' => [
            // 22 — spouse
            'spouse_surname' => 'D36',
            'spouse_first_name' => 'D37',
            'spouse_name_extension' => 'H37',
            'spouse_middle_name' => 'D38',
            'spouse_occupation' => 'D39',
            'spouse_employer' => 'D40',
            'spouse_business_address' => 'D41',
            'spouse_telephone_no' => 'D42',

            // 24 — father
            'father_surname' => 'D44',
            'father_first_name' => 'D45',
            'father_name_extension' => 'H45',
            'father_middle_name' => 'D46',

            // 25 — mother's maiden name
            'mother_surname' => 'D48',
            'mother_first_name' => 'D49',
            'mother_middle_name' => 'D50',
        ],
    ],

    /*
     * 23 — children. Thirteen printed rows, 37 to 49.
     *
     * Row 42 is not merged in the template while every other row is. Text
     * written there still reads across, so the row is used; it is noted here
     * because it looks like a mistake in the file rather than in this map.
     */
    'children' => [
        'sheet' => 'page_1',
        'continuation' => 'family_background_cont',
        'first_row' => 37,
        'row_count' => 13,
        'columns' => [
            'name' => 'I',
            'date_of_birth' => 'M',
        ],
    ],

    /*
     * III. EDUCATIONAL BACKGROUND — item 26, sheet C1.
     *
     * The printed form gives ONE row per level, fixed: 55 Elementary,
     * 56 Secondary, 57 Vocational, 58 College, 59 Graduate. The schema allows
     * more than one entry per level, because the CSC allows it — a second
     * master's degree goes to the C8 continuation sheet rather than replacing
     * the first.
     */
    'education' => [
        'sheet' => 'page_1',
        'continuation' => 'education_cont',
        'rows_by_level' => [
            'elementary' => 55,
            'secondary' => 56,
            'vocational' => 57,
            'college' => 58,
            'graduate' => 59,
        ],
        'columns' => [
            'school_name' => 'D',
            'degree_course' => 'G',
            'period_from' => 'J',
            'period_to' => 'K',
            'highest_level_units' => 'L',
            'year_graduated' => 'M',
            'honors' => 'N',
        ],
    ],

    /*
     * The 2026 revision prints a signature box and a date on every page, not
     * only the last, and labels the signature "(e-signature/digital
     * certificate)". Only the date is filled; what belongs in the signature
     * box is a question for the HR office, and until it is answered the form
     * is printed and signed by hand.
     */
    'page_dates' => [
        'page_1' => ['sheet' => 'page_1', 'cell' => 'L61'],
    ],

];
