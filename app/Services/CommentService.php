<?php

namespace App\Services;

use App\Enums\CommentSort;
use App\Events\CommentCreated;
use App\Http\Resources\CommentResource;
use App\Models\Comment;
use App\Repositories\CommentRepository;
use Illuminate\Support\Collection;

/**
 * Сценарии работы с комментариями. Контроллер вызывает только этот класс.
 */
class CommentService
{
    public function __construct(
        private readonly CommentRepository $comments,
        private readonly CommentCache $cache,
    ) {
    }

    /**
     * Страница списка комментариев вместе со всеми ответами (с кэшем).
     *
     * @return array{data: array<int, array<string, mixed>>, meta: array{current_page: int, last_page: int, total: int}}
     */
    public function list(CommentSort $sort, string $dir, int $page): array
    {
        return $this->cache->remember(
            $sort->value,
            $dir,
            $page,
            fn () => $this->buildPage($sort, $dir, $page)
        );
    }

    /**
     * Создаёт комментарий или ответ на комментарий.
     *
     * Кэш списка сбрасывается автоматически (событие created модели Comment).
     *
     * @param  array{user_name: string, email: string, home_page?: ?string, text: string, parent_id?: ?int}  $data
     */
    public function create(array $data): Comment
    {
        $parent = isset($data['parent_id']) ? $this->comments->findOrFail($data['parent_id']) : null;

        $comment = $this->comments->create([
            'user_name' => $data['user_name'],
            'email' => $data['email'],
            'home_page' => $data['home_page'] ?? null,
            'text' => CommentSanitizer::sanitize($data['text']),
        ], $parent);

        // недоступность брокера или Reverb не должна ломать сохранение комментария
        try {
            CommentCreated::dispatch($comment);
        } catch (\Throwable $e) {
            report($e);
        }

        return $comment;
    }

    private function buildPage(CommentSort $sort, string $dir, int $page): array
    {
        $paginator = $this->comments->paginateRoots($sort, $dir, $page);
        $roots = $paginator->getCollection();

        $replies = $this->comments->repliesGroupedByParent($roots->pluck('id'));
        $this->attachReplies($roots, $replies);

        return [
            // ресурс сразу превращается в обычный массив: в кэш кладём данные, а не объекты
            'data' => CommentResource::collection($roots)->response()->getData(true)['data'],
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /**
     * Рекурсивно подключает ответы к комментариям как загруженную связь replies.
     * Запросов к базе здесь нет: все ответы уже получены одним запросом.
     *
     * @param  Collection<int, Comment>  $comments
     * @param  Collection<int, Collection<int, Comment>>  $replies  ответы, сгруппированные по parent_id
     */
    private function attachReplies(Collection $comments, Collection $replies): void
    {
        foreach ($comments as $comment) {
            $children = $replies->get($comment->id, collect());
            $comment->setRelation('replies', $children);
            $this->attachReplies($children, $replies);
        }
    }
}
