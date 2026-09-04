<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
require __DIR__.'/employees.php';
require __DIR__.'/leave.php';
require __DIR__.'/organization.php';
require __DIR__.'/pds.php';
