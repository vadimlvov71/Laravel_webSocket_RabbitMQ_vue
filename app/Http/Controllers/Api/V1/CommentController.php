<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\SearchUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreCommentApiRequest;
use App\Http\Requests\ListCommentsRequest;
use App\Http\Requests\SearchCommentsRequest;
use App\Http\Resources\CommentResource;
use App\Models\Comment;
use App\Services\CommentSearch;
use App\Services\CommentService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\IgnoreResponse;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * REST API v1. Логики здесь нет: всё делает CommentService, как и для сайта.
 * Описание для OpenAPI берётся из этих PHPDoc (Scramble), поэтому они на английском.
 */
#[Group('Comments')]
class CommentController extends Controller
{
    public function __construct(private readonly CommentService $comments)
    {
    }

    /**
     * List comments.
     *
     * Root comments, 25 per page (`COMMENTS_PER_PAGE`), each with all replies nested in `replies`
     * at any depth. Sorting applies to root comments; replies are always oldest first.
     * Invalid `sort`, `dir` or `page` values fall back to the defaults.
     *
     * Requires ability `comments:read`.
     */
    #[QueryParameter('sort', description: 'Sort root comments by `user_name`, `email` or `created_at`.', type: 'string', default: 'created_at', example: 'user_name')]
    #[QueryParameter('dir', description: '`asc` or `desc`.', type: 'string', default: 'desc', example: 'asc')]
    #[QueryParameter('page', description: 'Page number, from 1.', type: 'integer', default: 1, example: 2)]
    // ListCommentsRequest не валидирует, а подставляет значения по умолчанию: 422 не бывает
    #[IgnoreResponse(422)]
    public function index(ListCommentsRequest $request): AnonymousResourceCollection
    {
        return CommentResource::collection(
            $this->comments->paginateWithReplies($request->sort(), $request->dir(), $request->page())
        );
    }

    /**
     * Get a comment.
     *
     * The comment with all replies to it (its subtree) nested in `replies`.
     *
     * Requires ability `comments:read`.
     */
    public function show(Comment $comment): CommentResource
    {
        return new CommentResource($this->comments->thread($comment));
    }

    /**
     * Search comments.
     *
     * Full-text search by text and author name (Elasticsearch): Russian and English word forms,
     * typos, prefix of the last word. Up to 10 best matches. `snippet` is HTML-escaped text where
     * matches are wrapped in `<mark>`; no other tags are possible.
     *
     * Requires ability `comments:read`.
     *
     * @response array{data: list<array{id: int, parent_id: int|null, root_id: int|null, user_name: string, created_at: string, snippet: string}>}
     */
    public function search(SearchCommentsRequest $request, CommentSearch $search): JsonResponse
    {
        try {
            return response()->json(['data' => $search->search($request->term())]);
        } catch (SearchUnavailableException $e) {
            report($e);

            /**
             * Search engine is unavailable; the rest of the API works.
             *
             * @status 503
             *
             * @body array{message: string}
             */
            return response()->json(['message' => 'Search is temporarily unavailable.'], 503);
        }
    }

    /**
     * Create a comment.
     *
     * Creates a comment or, with `parent_id`, a reply. Send `multipart/form-data` to attach a file:
     * a JPG/GIF/PNG image (up to 5 MB, scaled down to fit 320×240) or a TXT file (up to 100 KB).
     * `text` may contain only `<a href title>`, `<code>`, `<i>`, `<strong>`, properly closed and nested.
     * No CAPTCHA: the key itself identifies the client. The new comment appears on the site
     * in real time.
     *
     * Requires ability `comments:write`. Limit: 20 requests per minute per key.
     *
     * @status 201
     */
    public function store(StoreCommentApiRequest $request): JsonResponse
    {
        $comment = $this->comments->create($request->validated());

        return (new CommentResource($comment))->response()->setStatusCode(201);
    }
}
