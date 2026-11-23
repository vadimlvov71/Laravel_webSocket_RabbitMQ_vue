<?php

use App\Http\Controllers\Api\V1\CommentController;
use Illuminate\Support\Facades\Route;

/*
 * REST API v1 (префикс /api задаёт bootstrap/app.php). Только ключи Sanctum:
 * Authorization: Bearer <token>. Ключи выдаёт php artisan api:client-create.
 * Документация OpenAPI: /docs/api (генерирует Scramble из этого кода).
 */
Route::prefix('v1')
    ->middleware(['auth:sanctum', 'throttle:api'])
    ->name('api.v1.')
    ->group(function () {
        Route::middleware('ability:comments:read')->group(function () {
            Route::get('comments', [CommentController::class, 'index'])->name('comments.index');
            Route::get('comments/search', [CommentController::class, 'search'])->name('comments.search');
            Route::get('comments/{comment}', [CommentController::class, 'show'])
                ->whereNumber('comment')
                ->name('comments.show');
        });

        Route::post('comments', [CommentController::class, 'store'])
            ->middleware(['ability:comments:write', 'throttle:api-write'])
            ->name('comments.store');
    });
