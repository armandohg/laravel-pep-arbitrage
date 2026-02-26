<?php

use App\Livewire\ArbitrageDashboard;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('dashboard', ArbitrageDashboard::class)->name('dashboard');
});

require __DIR__.'/settings.php';
