<?php

use App\Http\Controllers\ApiDocumentationController;
use Illuminate\Support\Facades\Route;

Route::prefix('docs')->name('docs.')->middleware('throttle:api')->group(function (): void {
    Route::get('/', [ApiDocumentationController::class, 'index'])->name('index');
    Route::get('/openapi.json', [ApiDocumentationController::class, 'specification'])->name('openapi');
    Route::get('/assets/{asset}', [ApiDocumentationController::class, 'asset'])->name('asset');
});
