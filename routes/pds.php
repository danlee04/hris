<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('pds/personal-information', 'pages::pds.personal-information')
        ->name('pds.personal-information');

    Route::livewire('pds/family-background', 'pages::pds.family-background')
        ->name('pds.family-background');

    Route::livewire('pds/education', 'pages::pds.education')
        ->name('pds.education');

    Route::livewire('pds/eligibility', 'pages::pds.eligibility')
        ->name('pds.eligibility');

    Route::livewire('pds/work-experience', 'pages::pds.work-experience')
        ->name('pds.work-experience');

    Route::livewire('pds/voluntary-work', 'pages::pds.voluntary-work')
        ->name('pds.voluntary-work');

    Route::livewire('pds/learning-development', 'pages::pds.learning-development')
        ->name('pds.learning-development');

    Route::livewire('pds/other-information', 'pages::pds.other-information')
        ->name('pds.other-information');
});
