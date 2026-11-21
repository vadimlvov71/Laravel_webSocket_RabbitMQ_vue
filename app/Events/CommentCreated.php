<?php

namespace App\Events;

use App\Models\Comment;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Сигнал «появился комментарий» для открытых вкладок (канал comments).
 *
 * Отправляется через broadcast(...)->toOthers(): InteractsWithSockets запоминает
 * X-Socket-ID из запроса, и Reverb не шлёт событие соединению автора.
 */
class CommentCreated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public Comment $comment)
    {
    }

    public function broadcastOn(): array
    {
        return [new Channel('comments')];
    }

    public function broadcastAs(): string
    {
        return 'comment.created';
    }

    /**
     * Только то, что нужно для уведомления. Имена и так видны в списке;
     * e-mail и текст не рассылаем: список вкладка запросит сама через GET /comments.
     *
     * @return array{id: int, parent_id: ?int, root_id: ?int, user_name: string, parent_user_name: ?string}
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->comment->id,
            'parent_id' => $this->comment->parent_id,
            'root_id' => $this->comment->root_id,
            'user_name' => $this->comment->user_name,
            'parent_user_name' => $this->comment->parent?->user_name,
        ];
    }
}
