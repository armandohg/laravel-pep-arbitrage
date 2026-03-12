<?php

use App\Livewire\ArbitrageDashboard;
use App\Livewire\TransfersList;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('dashboard', ArbitrageDashboard::class)->name('dashboard');
    Route::livewire('transfers', TransfersList::class)->name('transfers');
});

require __DIR__.'/settings.php';
