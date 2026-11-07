<?php

use App\Http\Controllers\DailyReportController;
use App\Http\Middleware\RestrictReportNetwork;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/reportes', DailyReportController::class)
    ->middleware(RestrictReportNetwork::class);
