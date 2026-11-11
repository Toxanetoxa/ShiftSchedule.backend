<?php

use App\Http\Controllers\Api\V1\CalendarController;
use App\Http\Controllers\Api\V1\PublicationController;
use App\Http\Controllers\Api\V1\SaveScheduleCellsController;
use App\Http\Middleware\AccessDemoSchedule;
use App\Http\Middleware\ValidateScheduleJson;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

Route::get('/', function (): JsonResponse {
    return response()->json([
        'service' => config('app.name'),
        'status' => 'ok',
    ]);
})->name('api.info');

Route::prefix('schedules/{scheduleId}')->middleware([AccessDemoSchedule::class, ValidateScheduleJson::class])->group(function (): void {
    Route::get('/calendar', CalendarController::class)->name('schedules.calendar');
    Route::patch('/cells', SaveScheduleCellsController::class)->name('schedules.cells.update');
    Route::post('/publications', [PublicationController::class, 'store'])->name('schedules.publications.store');
    Route::get('/publications/{publicationId}', [PublicationController::class, 'show'])->name('schedules.publications.show');
});
