<?php

namespace App\Events;

use App\Models\Comment;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CommentCreated implements ShouldBroadcast
{
    use Dispatchable;
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

    public function broadcastWith(): array
    {
        return ['id' => $this->comment->id, 'parent_id' => $this->comment->parent_id];
    }
}
