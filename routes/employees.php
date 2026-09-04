<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('employees', 'pages::employees.index')->name('employees.index');

    // Keep this above any route with a wildcard segment, or `import` gets
    // swallowed as an employee id once one exists.
    Route::livewire('employees/import', 'pages::employees.import')->name('employees.import');

    Route::livewire('employees/issue-account', 'pages::employees.issue-account')
        ->name('employees.issue-account');

    Route::livewire('employees/{employee}/edit', 'pages::employees.edit')
        ->name('employees.edit');

    Route::livewire('audit', 'pages::audit.index')->name('audit.index');
});
