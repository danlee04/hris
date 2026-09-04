<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('employees', 'pages::employees.index')->name('employees.index');

    // Keep these above the wildcard, or they get swallowed as an employee id
    // once one exists.
    Route::livewire('employees/create', 'pages::employees.form')->name('employees.create');

    Route::livewire('employees/issue-account', 'pages::employees.issue-account')
        ->name('employees.issue-account');

    // One employee. Correcting them is a modal on this page and on the list;
    // there is no edit page, so there is one place the form can be opened from
    // and one shape it can be in.
    Route::livewire('employees/{employee}', 'pages::employees.show')->name('employees.show');

    Route::livewire('audit', 'pages::audit.index')->name('audit.index');
});
