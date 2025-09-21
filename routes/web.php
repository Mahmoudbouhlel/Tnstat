<?php

use App\Http\Controllers\MatchController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

 Route::get('/home', function () {
    return Inertia::render('Welcome');
})->name('home');
/*
Route::get('/', function () {
    return Inertia::render('Dashboard');
})->name('dashboard'); */
Route::get('/', [MatchController::class, 'index'])->name('dashboard');

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
