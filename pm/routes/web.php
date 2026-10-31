<?php

use Illuminate\Support\Facades\Route;
use Pterodactyl\BlueprintFramework\Extensions\mcmanager\ManagerController;

Route::get('/info', [ManagerController::class, 'info']);
Route::get('/search', [ManagerController::class, 'search']);
Route::get('/versions', [ManagerController::class, 'versions']);
Route::post('/install', [ManagerController::class, 'install']);
Route::get('/installed', [ManagerController::class, 'installed']);
Route::post('/toggle', [ManagerController::class, 'toggle']);
Route::post('/remove', [ManagerController::class, 'remove']);
Route::get('/players', [ManagerController::class, 'players']);
Route::post('/action', [ManagerController::class, 'action']);
