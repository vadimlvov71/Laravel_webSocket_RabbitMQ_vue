<?php

namespace App\Http\Controllers;

use App\Events\CommentCreated;
use App\Http\Requests\StoreCommentRequest;
use App\Models\Comment;
use App\Services\CommentSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class CommentController extends Controller
{
    private const SORTABLE = ['user_name', 'email', 'created_at'];
    private const PER_PAGE = 25;

    public function index(Request $request): JsonResponse
    {
        $sort = in_array($request->query('sort'), self::SORTABLE, true) ? $request->query('sort') : 'created_at';
        $dir = $request->query('dir') === 'asc' ? 'asc' : 'desc'; // по умолчанию LIFO

        $page = Comment::query()
            ->whereNull('parent_id')
            ->orderBy($sort, $dir)
            ->orderBy('id', $dir)
            ->paginate(self::PER_PAGE);

        // все ответы любой глубины для комментариев текущей страницы: один запрос
        $replies = Comment::query()
            ->whereIn('root_id', $page->pluck('id'))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->groupBy('parent_id');

        return response()->json([
            'data' => $page->getCollection()->map(fn (Comment $c) => $this->node($c, $replies))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function store(StoreCommentRequest $request): JsonResponse
    {
        $data = $request->validated();
        $parent = isset($data['parent_id']) ? Comment::findOrFail($data['parent_id']) : null;

        $comment = Comment::create([
            'parent_id' => $parent?->id,
            'root_id' => $parent ? ($parent->root_id ?? $parent->id) : null,
            'user_name' => $data['user_name'],
            'email' => $data['email'],
            'home_page' => $data['home_page'] ?? null,
            'text' => CommentSanitizer::sanitize($data['text']),
        ]);

        CommentCreated::dispatch($comment);

        return response()->json(['id' => $comment->id], 201);
    }

    private function node(Comment $comment, Collection $replies): array
    {
        return [
            'id' => $comment->id,
            'user_name' => $comment->user_name,
            'email' => $comment->email,
            'home_page' => $comment->home_page,
            'text' => $comment->text,
            'created_at' => $comment->created_at->toIso8601String(),
            'replies' => $replies->get($comment->id, collect())
                ->map(fn (Comment $r) => $this->node($r, $replies))
                ->values(),
        ];
    }
}