<?php

use Illuminate\Support\Facades\Route;
use AliReaza\Laravel\Request\Middleware\FormData;
use AliReaza\Laravel\Gateway\Controllers\RequestController;

Route::group([
    'middleware' => ['api', FormData::class],
    'namespace' => 'AliReaza\Laravel\Gateway\Controllers',
    'prefix' => 'api',
], function (): void {
    Route::group(['prefix' => '/'], function (): void {
        Route::any('/', RequestController::class);
        Route::match(['COPY', 'LINK', 'UNLINK', 'PURGE', 'LOCK', 'UNLOCK', 'PROPFIND', 'VIEW'], '/', RequestController::class);
    })->name('request');

    Route::get('/{correlation_id}', RequestController::class)->name('correlation');

    Route::get('/file/{hash}/{name}', [RequestController::class, 'file'])->name('file');
});
