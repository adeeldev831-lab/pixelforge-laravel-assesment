<?php

use App\Http\Controllers\AdminReportController;
use App\Http\Controllers\CreditPurchaseController;
use App\Http\Controllers\GenerationController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/generations')->name('home');

Route::middleware('auth')->group(function () {
    Route::get('generations', [GenerationController::class, 'index'])->name('generations.index');
    Route::post('generations', [GenerationController::class, 'store'])->name('generations.store');
    Route::post('generations/estimate', [GenerationController::class, 'estimate'])->name('generations.estimate');

    Route::post('credits', [CreditPurchaseController::class, 'store'])->name('credits.store');

    Route::get('admin/report', AdminReportController::class)->can('viewAdminReport')->name('admin.report');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
