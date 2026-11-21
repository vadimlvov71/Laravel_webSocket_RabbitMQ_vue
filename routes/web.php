<?php

use App\Http\Controllers\CommentController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'comments')->name('home');

Route::get('/comments', [CommentController::class, 'index'])->name('comments.index');
Route::get('/comments/search', [CommentController::class, 'search'])
    ->middleware('throttle:60,1')
    ->name('comments.search');
Route::get('/comments/{comment}/page', [CommentController::class, 'page'])->name('comments.page');
Route::post('/comments', [CommentController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('comments.store');
