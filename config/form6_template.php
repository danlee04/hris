<?php

/*
|--------------------------------------------------------------------------
| CS Form No. 6 (Revised 2020), on DTRC's letterhead
|--------------------------------------------------------------------------
|
| Every cell reference the exporter uses. Read off the file, not off the CSC
| issuance: the hospital's copy carries its own document code, its own
| effectivity, and its own arrangement of the two panels.
|
| Three things about this template are easy to get wrong.
|
| 1. The checkboxes have no cell of their own in the file the hospital
|    maintains. `php artisan form6:link` writes the links into the copy named
|    below, in columns R and T. Both sit outside the print area ($A$2:$J$69),
|    so nothing about them reaches the paper.
|
| 2. Seven fields have no input cell at all — the blank is inside the caption
|    string. They are listed under `captions` and the whole caption is
|    rewritten. Change the wording on the form and the format here has to
|    change with it.
|
| 3. `A10`, `E10` and `A69` are RichText. Writing a plain string flattens the
|    underlined blanks. Accepted, deliberately.
|
*/

return [

    'path' => storage_path('app/templates/CS Form No. 6 (Application for Leave) DTRC linked.xlsx'),

    'sheet' => 'CS Form No. 6, Rev 2020 1 of 2',

    'date_format' => 'd/m/Y',

    'cells' => [
        'office' => 'B9',
        'name' => 'E9',
        'days' => 'C49',
        'inclusive_dates' => 'C52',

        'other_type' => 'B45',

        'vacation_earned' => 'D60',
        'sick_earned' => 'E60',
        'vacation_less' => 'D61',
        'sick_less' => 'E61',
        'vacation_balance' => 'D62',
        'sick_balance' => 'E62',

        'hr_name' => 'C63',
        'division_head_name' => 'I63',

        'disapproval_reason' => 'I60',
        'disapproved_due_to' => 'H66',

        // Item 6.B free text. Only the sick-leave and women's-leave blocks have
        // a blank line of their own; the other two have to be written into
        // their captions, below.
        'sick_detail' => 'I27',
        'women_detail' => 'I33',
    ],

    'captions' => [
        'date_of_filing' => ['cell' => 'A10', 'format' => '3.   DATE OF FILING   :value'],
        'position' => ['cell' => 'E10', 'format' => '4.   POSITION   :value'],
        'salary' => ['cell' => 'J10', 'format' => '5.  SALARY   :value'],
        'as_of' => ['cell' => 'C57', 'format' => 'As of :value'],
        'days_with_pay' => ['cell' => 'C66', 'format' => ':value days with pay'],
        'days_without_pay' => ['cell' => 'C67', 'format' => ':value days without pay'],
        'days_others' => ['cell' => 'C68', 'format' => ':value others (Specify)'],

        // The vacation and study blocks have no blank line of their own, so
        // their free text goes into the caption beside the box it belongs to.
        'vacation_within' => ['cell' => 'I17', 'format' => 'Within the Philippines  :value'],
        'vacation_abroad' => ['cell' => 'I19', 'format' => 'Abroad (Specify)  :value'],
        'study_other' => ['cell' => 'I41', 'format' => 'Other purpose:  :value'],
    ],

    'ticks' => [

        // Keyed on leave_types.code. Wellness is deliberately absent: it has no
        // box on this form and prints on the "Others:" line instead.
        'types' => [
            'VL' => 'R15',
            'FL' => 'R17',
            'SL' => 'R19',
            'ML' => 'R21',
            'PL' => 'R23',
            'SPL' => 'R25',
            'SOLO' => 'R27',
            'STUDY' => 'R29',
            'VAWC' => 'R31',
            'REHAB' => 'R33',
            'SLBW' => 'R35',
            'CALAMITY' => 'R37',
            'ADOPTION' => 'R39',
        ],

        'vacation_where' => [
            'within_philippines' => 'T17',
            'abroad' => 'T19',
        ],

        'sick_where' => [
            'in_hospital' => 'T23',
            'out_patient' => 'T25',
        ],

        'study_purpose' => [
            'masters' => 'T37',
            'board_review' => 'T39',
        ],

        'benefit' => [
            'monetization' => 'T43',
            'terminal' => 'T45',
        ],

        'commutation' => [
            'not_requested' => 'T49',
            'requested' => 'T51',
        ],

        'recommendation' => [
            'approve' => 'T57',
            'disapprove' => 'T59',
        ],
    ],
];
