<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON-представление комментария.
 *
 * Ответы (replies) выводятся рекурсивно, если связь загружена
 * (см. CommentService::attachReplies), поэтому глубина вложенности не ограничена.
 *
 * @mixin \App\Models\Comment
 */
class CommentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_name' => $this->user_name,
            'email' => $this->email,
            'home_page' => $this->home_page,
            'text' => $this->text,
            'created_at' => $this->created_at->toIso8601String(),
            'replies' => self::collection($this->whenLoaded('replies')),
        ];
    }
}
