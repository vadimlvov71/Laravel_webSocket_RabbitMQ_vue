<?php

namespace App\Services;

use App\Enums\CommentSort;
use App\Events\CommentCreated;
use App\Http\Resources\CommentResource;
use App\Models\Comment;
use App\Repositories\CommentRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

/**
 * Сценарии работы с комментариями. Контроллер вызывает только этот класс.
 */
class CommentService
{
    public function __construct(
        private readonly CommentRepository $comments,
        private readonly CommentCache $cache,
        private readonly AttachmentStorage $attachments,
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
     * Вложение сохраняется до записи в БД; если запись не удалась, файл удаляется,
     * чтобы на диске не оставалось файлов без комментария.
     *
     * @param  array{user_name: string, email: string, home_page?: ?string, text: string, parent_id?: ?int, attachment?: ?UploadedFile}  $data
     */
    public function create(array $data): Comment
    {
        $parent = isset($data['parent_id']) ? $this->comments->findOrFail($data['parent_id']) : null;

        $file = $data['attachment'] ?? null;
        $attachment = $file instanceof UploadedFile ? $this->attachments->store($file) : [];

        try {
            $comment = $this->comments->create([
                'user_name' => $data['user_name'],
                'email' => $data['email'],
                'home_page' => $data['home_page'] ?? null,
                'text' => CommentSanitizer::sanitize($data['text']),
                ...$attachment,
            ], $parent);
        } catch (\Throwable $e) {
            if ($attachment !== []) {
                $this->attachments->delete($attachment['attachment_path']);
            }

            throw $e;
        }

        // недоступность брокера или Reverb не должна ломать сохранение комментария;
        // toOthers: вкладка автора и так обновит список после ответа 201
        try {
            broadcast(new CommentCreated($comment))->toOthers();
        } catch (\Throwable $e) {
            report($e);
        }

        return $comment;
    }

    /**
     * Номер страницы, на которой ветка комментария видна при данной сортировке.
     *
     * @return array{page: int, root_id: int}
     */
    public function locate(Comment $comment, CommentSort $sort, string $dir): array
    {
        $root = $comment->root_id ? $this->comments->findOrFail($comment->root_id) : $comment;

        return ['page' => $this->comments->pageOfRoot($root, $sort, $dir), 'root_id' => $root->id];
    }

    /**
     * Страница корневых комментариев, у каждого загружены ответы всех уровней
     * (связь replies). Без кэша: для REST API, где ответ строит CommentResource.
     *
     * @return LengthAwarePaginator<int, Comment>
     */
    public function paginateWithReplies(CommentSort $sort, string $dir, int $page): LengthAwarePaginator
    {
        $paginator = $this->comments->paginateRoots($sort, $dir, $page);
        $roots = $paginator->getCollection();

        $this->attachReplies($roots, $this->comments->repliesGroupedByParent($roots->pluck('id')));

        return $paginator;
    }

    /**
     * Комментарий с ответами на него всех уровней (поддерево, а не вся ветка от корня).
     * Ответы берутся одним запросом по root_id, как и для списка.
     */
    public function thread(Comment $comment): Comment
    {
        $replies = $this->comments->repliesGroupedByParent(collect([$comment->root_id ?? $comment->id]));
        $this->attachReplies(collect([$comment]), $replies);

        return $comment;
    }

    private function buildPage(CommentSort $sort, string $dir, int $page): array
    {
        $paginator = $this->paginateWithReplies($sort, $dir, $page);
        $roots = $paginator->getCollection();

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
