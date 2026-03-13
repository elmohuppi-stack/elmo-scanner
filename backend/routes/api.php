<?php

use App\Http\Controllers\Api\ArticleController;
use App\Http\Controllers\Api\FeedController;
use Illuminate\Support\Facades\Route;

Route::get('/feeds', [FeedController::class, 'index']);
Route::post('/feeds', [FeedController::class, 'store']);
Route::post('/admin/feeds/{feed}/fetch', [FeedController::class, 'fetch']);
Route::get('/articles', [ArticleController::class, 'index']);
