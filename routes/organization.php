<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('organization/divisions', 'pages::organization.divisions')
        ->name('organization.divisions');

    Route::livewire('organization/sections', 'pages::organization.sections')
        ->name('organization.sections');

    Route::livewire('organization/positions', 'pages::organization.positions')
        ->name('organization.positions');
});
