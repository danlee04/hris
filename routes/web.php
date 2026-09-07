<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    // A Livewire page rather than a plain view: it runs queries and it shows
    // different things to different people, and Route::view can do neither.
    Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
require __DIR__.'/employees.php';
require __DIR__.'/leave.php';
require __DIR__.'/organization.php';
require __DIR__.'/pds.php';
