<?php

use App\Http\Controllers\Api\ArticleController;
use App\Http\Controllers\Api\FeedController;
use Illuminate\Support\Facades\Route;

Route::get('/feeds', [FeedController::class, 'index']);
Route::post('/feeds', [FeedController::class, 'store']);
Route::patch('/feeds/reorder', [FeedController::class, 'reorder']);
Route::patch('/feeds/{feed}', [FeedController::class, 'update']);
Route::delete('/feeds/{feed}', [FeedController::class, 'destroy']);
Route::post('/admin/feeds/{feed}/fetch', [FeedController::class, 'fetch']);
Route::post('/admin/feeds/fetch-all', [FeedController::class, 'fetchAll']);
Route::get('/articles', [ArticleController::class, 'index']);
Route::get('/articles/{article}', [ArticleController::class, 'show']);
