<?php

namespace App\Jobs;

use App\Models\Comment;
use App\Services\CommentSearch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Приводит документ в Elasticsearch к состоянию комментария в БД:
 * комментарий есть — записать, нет — удалить. Поэтому одна задача подходит
 * и для создания, и для удаления, а повтор ничего не ломает.
 *
 * Идёт через очередь default (воркер queue-default): сохранение комментария
 * не ждёт Elasticsearch и не падает, если он недоступен, задача повторится.
 */
class SyncCommentToSearch implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> секунды между попытками: Elasticsearch мог перезапускаться */
    public array $backoff = [2, 10, 30, 60];

    public function __construct(public int $commentId)
    {
        $this->onQueue('default');
    }

    public function handle(CommentSearch $search): void
    {
        $comment = Comment::find($this->commentId);

        $comment ? $search->index($comment) : $search->delete($this->commentId);
    }
}
