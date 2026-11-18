<?php

use App\Http\Controllers\EstimateGenerationController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\StoreGenerationController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
    Route::get('generations', [GenerationController::class, 'index'])->name('generations.index');
    Route::get('generations/create', [GenerationController::class, 'create'])->name('generations.create');
    Route::get('generations/estimate', EstimateGenerationController::class)->name('generations.estimate');
    Route::post('generations', StoreGenerationController::class)->name('generations.store');
});

require __DIR__.'/settings.php';
