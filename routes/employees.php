<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('employees', 'pages::employees.index')->name('employees.index');
});
