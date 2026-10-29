<?php

use App\Http\Controllers\AppDownloadPageController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/download', AppDownloadPageController::class)->name('downloads.page');
Route::get('/download/ios', [AppDownloadPageController::class, 'ios'])->name('downloads.ios');
