<?php

use App\Http\Controllers\CommentController;
use Illuminate\Support\Facades\Route;
 
Route::view('/', 'comments')->name('home');
 
Route::get('/comments', [CommentController::class, 'index'])->name('comments.index');
Route::post('/comments', [CommentController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('comments.store');
