<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('employees', 'pages::employees.index')->name('employees.index');

    // Keep these above any route with a wildcard segment, or they get swallowed
    // as an employee id once one exists.
    Route::livewire('employees/create', 'pages::employees.form')->name('employees.create');

    Route::livewire('employees/issue-account', 'pages::employees.issue-account')
        ->name('employees.issue-account');

    // Same component as employees/create. Adding a person and correcting one
    // are the same fourteen fields with the same rules.
    Route::livewire('employees/{employee}/edit', 'pages::employees.form')
        ->name('employees.edit');

    Route::livewire('audit', 'pages::audit.index')->name('audit.index');
});
