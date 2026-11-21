<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListCommentsRequest;
use App\Http\Requests\StoreCommentRequest;
use App\Models\Comment;
use App\Services\CommentService;
use Illuminate\Http\JsonResponse;

class CommentController extends Controller
{
    public function __construct(private readonly CommentService $comments)
    {
    }

    /**
     * Страница списка комментариев вместе со всеми ответами.
     *
     * Параметры запроса: sort (user_name|email|created_at), dir (asc|desc), page.
     * По умолчанию сортировка по дате по убыванию (LIFO).
     *
     * Формат ответа:
     * {data: [{id, user_name, email, home_page, text, created_at, attachment, replies: [...]}],
     *  meta: {current_page, last_page, total}}
     */
    public function index(ListCommentsRequest $request): JsonResponse
    {
        return response()->json($this->comments->list($request->sort(), $request->dir(), $request->page()));
    }

    /**
     * На какой странице списка (при текущей сортировке) находится ветка комментария.
     * Нужен кнопке «Показать» в уведомлении: ответ может быть в старой ветке на другой странице.
     *
     * Формат ответа: {page, root_id}
     */
    public function page(ListCommentsRequest $request, Comment $comment): JsonResponse
    {
        return response()->json($this->comments->locate($comment, $request->sort(), $request->dir()));
    }

    /** Сохраняет комментарий или ответ на комментарий. */
    public function store(StoreCommentRequest $request): JsonResponse
    {
        $comment = $this->comments->create($request->validated());

        return response()->json(['id' => $comment->id], 201);
    }
}
