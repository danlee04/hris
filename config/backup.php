<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Where backups are written
    |--------------------------------------------------------------------------
    |
    | Point this at a network share or an external drive. A backup sitting on
    | the same disk as the database is not a backup — one failed drive, one
    | ransomware run, or one stolen tower takes both at once. `storage/backups`
    | is a starting place, not a destination.
    |
    */

    // `?:` and not a default argument: dotenv reads a bare `BACKUP_PATH=` as an
    // empty string, which is a value, so the default would never apply and the
    // archive would be written to the filesystem root.
    'path' => env('BACKUP_PATH') ?: storage_path('backups'),

    /*
    |--------------------------------------------------------------------------
    | How long to keep them
    |--------------------------------------------------------------------------
    |
    | Days. Old archives are deleted after a successful run and never before
    | one, so a failing backup cannot quietly eat the good ones behind it.
    |
    */

    'keep_days' => (int) env('BACKUP_KEEP_DAYS', 14),

    /*
    |--------------------------------------------------------------------------
    | Directories that go in alongside the database
    |--------------------------------------------------------------------------
    |
    | The database holds the records; these hold the files those records point
    | at. A restore with one and not the other is a system full of broken
    | references. `livewire-tmp` is left out — it is half-finished uploads.
    |
    */

    'include' => [
        storage_path('app/private'),
        storage_path('app/templates'),
    ],

    'exclude' => [
        'livewire-tmp',
    ],

    /*
    |--------------------------------------------------------------------------
    | Where mysqldump lives
    |--------------------------------------------------------------------------
    |
    | Laragon keeps it inside its own MySQL folder and does not put it on the
    | PATH, so the bare name fails with "command not found" on a machine where
    | MySQL is plainly running. Set the full path there, for example
    | C:\laragon\bin\mysql\mysql-8.0.30-winx64\bin\mysqldump.exe
    |
    */

    'mysqldump' => env('BACKUP_MYSQLDUMP', 'mysqldump'),

];
