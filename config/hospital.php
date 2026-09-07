<?php

return [

    /*
    |--------------------------------------------------------------------------
    | The letterhead
    |--------------------------------------------------------------------------
    |
    | Printed at the top of every certificate the system issues. These are not
    | hardcoded into the view because an office moves, a name is revised by a
    | department order, and neither should need a deployment.
    |
    | `address` and `contact` are blank until somebody fills them in, and the
    | letterhead simply omits the line rather than printing a placeholder. An
    | invented address on a document going to a bank is worse than no address.
    |
    */

    'republic' => env('HOSPITAL_REPUBLIC', 'Republic of the Philippines'),

    'department' => env('HOSPITAL_DEPARTMENT', 'Department of Health'),

    'name' => env('HOSPITAL_NAME', 'DOH Treatment and Rehabilitation Centre'),

    'unit' => env('HOSPITAL_UNIT', 'Surigao'),

    'address' => env('HOSPITAL_ADDRESS', ''),

    'contact' => env('HOSPITAL_CONTACT', ''),

];
