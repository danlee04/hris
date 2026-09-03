<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('pds/personal-information', 'pages::pds.personal-information')
        ->name('pds.personal-information');

    Route::livewire('pds/family-background', 'pages::pds.family-background')
        ->name('pds.family-background');

    Route::livewire('pds/education', 'pages::pds.education')
        ->name('pds.education');
});
