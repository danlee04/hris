<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('leave/types', 'pages::leave.types')->name('leave.types');

    Route::livewire('leave/ledger/{employee}', 'pages::leave.ledger')->name('leave.ledger');
});
